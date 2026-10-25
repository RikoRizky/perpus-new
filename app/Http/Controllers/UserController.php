<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index(Request $request)
    {
        $search = $request->search ?? '';
        $role   = $request->role ?? 'all';
        
        $query = User::query();
        
        if ($role && in_array($role, ['admin', 'petugas', 'petugas_stok', 'pengunjung'])) {
            $query->where('role', $role);
        }
        
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('npm', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        
        // Count totals for each group
        $counts = [
            'all'          => User::count(),
            'admin'        => User::where('role', 'admin')->count(),
            'petugas'      => User::where('role', 'petugas')->count(),
            'petugas_stok' => User::where('role', 'petugas_stok')->count(),
            'pengunjung'   => User::where('role', 'pengunjung')->count(),
        ];
        
        // Sort with Admin first, then Petugas, then Petugas Stok, then Pengunjung
        $users = $query->orderByRaw("CASE 
            WHEN role = 'admin' THEN 1 
            WHEN role = 'petugas' THEN 2 
            WHEN role = 'petugas_stok' THEN 3
            WHEN role = 'pengunjung' THEN 4 
            ELSE 5 END")
            ->latest('id')
            ->paginate(10)
            ->withQueryString();
        
        return view('admin.users.index', compact('users', 'search', 'role', 'counts'));
    }

    /**
     * Show the form for creating a new user (petugas only).
     */
    public function create()
    {
        return view('admin.users.form', [
            'user'   => null,
            'action' => 'create',
        ]);
    }

    /**
     * Store a newly created user (petugas) in storage.
     */
    public function store(Request $request)
    {
        $isPetugas = (auth()->check() && auth()->user()->isPetugas()) || $request->routeIs('petugas.*');

        $rules = [
            'npm'      => 'nullable|string|max:20|unique:users,npm',
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'role'     => $isPetugas ? 'nullable|in:pengunjung' : 'required|in:admin,petugas,petugas_stok,pengunjung',
            'password' => 'required|string|min:6|confirmed',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $data = $validator->validated();
        if ($isPetugas) {
            $data['role'] = 'pengunjung';
        }

        if ($data['role'] !== 'pengunjung') {
            $data['npm'] = null;
        }
        $data['password'] = Hash::make($data['password']);

        $newUser = User::create($data);

        // Buat kartu anggota digital otomatis untuk pengunjung
        if ($newUser->isPengunjung()) {
            \App\Models\MemberBarcode::getOrCreateForUser($newUser);
        }

        if ($isPetugas) {
            return redirect()->route('petugas.dashboard')
                ->with('success', 'Akun pengunjung ' . $newUser->name . ' berhasil dibuat!');
        }

        return redirect()->route('admin.users.index')
            ->with('success', 'Akun berhasil dibuat!');
    }

    /**
     * Display the specified user details.
     */
    public function show(User $user)
    {
        $loanStats = [
            'total'    => 0,
            'active'   => 0,
            'returned' => 0,
            'overdue'  => 0,
        ];

        if ($user->isPengunjung()) {
            // Pastikan kartu anggota digital ada / dibuatkan otomatis jika belum ada
            $memberBarcode = \App\Models\MemberBarcode::getOrCreateForUser($user);
            $user->setRelation('memberBarcode', $memberBarcode);

            $user->load([
                'loans' => function($q) {
                    $q->with('book')->latest();
                },
            ]);

            $loanStats = [
                'total'    => $user->loans->count(),
                'active'   => $user->loans->filter(fn($l) => in_array($l->getActualStatus(), ['peminjaman', 'menunggu_pengembalian']))->count(),
                'returned' => $user->loans->filter(fn($l) => $l->getActualStatus() === 'dikembalikan')->count(),
                'overdue'  => $user->loans->filter(fn($l) => $l->getActualStatus() === 'terlambat')->count(),
            ];
        }

        return view('admin.users.show', compact('user', 'loanStats'));
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user)
    {
        return view('admin.users.form', [
            'user'   => $user,
            'action' => 'edit',
        ]);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'npm'      => 'nullable|string|max:20|unique:users,npm,' . $user->id,
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email,' . $user->id,
            'role'     => 'required|in:admin,petugas,petugas_stok,pengunjung',
            'password' => 'nullable|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $data = $validator->validated();
        // Role admin bersifat permanen dan tidak dapat diubah
        if ($user->role === 'admin') {
            $data['role'] = 'admin';
        }

        if ($data['role'] !== 'pengunjung') {
            $data['npm'] = null;
        }

        // Only update password if provided
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return redirect()->route('admin.users.index')
            ->with('success', 'User berhasil diperbarui!');
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user)
    {
        // Prevent admin from deleting themselves
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'Anda tidak dapat menghapus akun Anda sendiri!');
        }

        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', 'User berhasil dihapus!');
    }
}

