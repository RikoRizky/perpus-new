<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Book;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class LoanController extends Controller
{
    /**
     * Display a listing of the loans for admin.
     */
    public function adminIndex(Request $request)
    {
        // Auto-expire barcodes that have passed their expiry time
        $this->autoExpireBarcodes();

        // Update status for overdue loans (only active loans)
        Loan::whereIn('status', [Loan::STATUS_PEMINJAMAN])
            ->whereNull('return_date')
            ->where('due_date', '<', now()->toDateString())
            ->update(['status' => Loan::STATUS_TERLAMBAT]);

        // Get UNFILTERED counts for stats cards
        $allLoans = Loan::all();
        $stats = [
            'menunggu'   => $allLoans->filter(fn($l) => in_array($l->getActualStatus(), ['menunggu_konfirmasi', 'menunggu_pengembalian']))->count(),
            'active'     => $allLoans->filter(fn($l) => in_array($l->getActualStatus(), ['peminjaman', 'menunggu_pengembalian']))->count(),
            'overdue'    => $allLoans->filter(fn($l) => $l->getActualStatus() === 'terlambat')->count(),
            'returned'   => $allLoans->filter(fn($l) => $l->getActualStatus() === 'dikembalikan')->count(),
        ];

        // Build filtered query for the table
        $query = Loan::with('user', 'book')->orderBy('created_at', 'desc');

        // Filter by search (nama atau NPM user)
        if ($request->has('search') && $request->search) {
            $query->whereHas('user', function($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('npm', 'like', '%' . $request->search . '%');
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $loans = $query->paginate(10)->withQueryString();

        return view('admin.loans.index', compact('loans', 'stats'));
    }

    /**
     * Display user's loans
     */
    public function myLoans()
    {
        // Auto-expire barcodes
        $this->autoExpireBarcodes();

        // Update status for overdue loans
        Loan::where('user_id', Auth::id())
            ->where('status', Loan::STATUS_PEMINJAMAN)
            ->where('due_date', '<', now()->toDateString())
            ->whereNull('return_date')
            ->update(['status' => Loan::STATUS_TERLAMBAT]);

        $user = Auth::user();
        $loans = Loan::where('user_id', $user->id)
            ->with('book')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('my-loans', compact('loans'));
    }

    /**
     * Show the form for creating a new loan (admin).
     */
    public function adminCreate()
    {
        $users = User::where('role', 'pengunjung')->get();
        $books = Book::where('stock', '>', 0)->get();

        return view('admin.loans.create', compact('users', 'books'));
    }

    /**
     * Store a newly created loan in database (admin).
     */
    public function adminStore(Request $request)
    {
        $validated = $request->validate([
            'user_id'   => 'required|exists:users,id',
            'book_id'   => 'required|exists:books,id',
            'loan_date' => 'required|date',
            'due_date'  => 'required|date|after_or_equal:loan_date',
            'notes'     => 'nullable|string',
        ]);

        // Check if book stock is available
        $book = Book::find($validated['book_id']);
        if ($book->stock <= 0) {
            return back()->withInput()->withErrors(['book_id' => 'Stok buku tidak tersedia']);
        }

        // Check if user already has an active loan for this book
        $existingLoan = Loan::where('user_id', $validated['user_id'])
            ->where('book_id', $validated['book_id'])
            ->whereIn('status', Loan::activeStatuses())
            ->first();

        if ($existingLoan) {
            return back()->withInput()->withErrors(['book_id' => 'Pengunjung ini masih memiliki pinjaman aktif untuk buku ini.']);
        }

        // Creates loan directly as active (bypass barcode flow for visitors without smartphone)
        $loan = Loan::create(array_merge($validated, [
            'status' => Loan::STATUS_PEMINJAMAN,
        ]));

        // Reduce book stock immediately
        $book->decrement('stock');

        $isPetugas = (auth()->check() && auth()->user()->isPetugas()) || $request->routeIs('petugas.*');

        if ($isPetugas) {
            return redirect()->route('petugas.dashboard')
                ->with('success', 'Peminjaman manual untuk ' . ($loan->user->name ?? 'Pengunjung') . ' (' . $book->title . ') berhasil dicatat!');
        }

        return redirect()->route('admin.loans.index')
            ->with('success', 'Peminjaman berhasil ditambahkan');
    }

    /**
     * User borrow a book — generates barcode, reduces stock immediately.
     */
    public function borrow(Request $request)
    {
        if (!Auth::user()->isProfileComplete()) {
            return redirect()->route('profile')->with('error', 'Mohon lengkapi biodata (termasuk NPM) Anda terlebih dahulu sebelum meminjam buku.');
        }

        $validated = $request->validate([
            'book_id'  => 'required|exists:books,id',
            'due_date' => 'required|date|after:today',
        ]);

        // Check if book stock is available
        $book = Book::find($validated['book_id']);
        if ($book->stock <= 0) {
            return back()->withErrors(['book_id' => 'Maaf, stok buku tidak tersedia']);
        }

        // Check if user already has this book borrowed/pending
        $existingLoan = Loan::where('user_id', Auth::id())
            ->where('book_id', $validated['book_id'])
            ->whereIn('status', Loan::activeStatuses())
            ->first();

        if ($existingLoan) {
            $msg = match($existingLoan->status) {
                Loan::STATUS_MENUNGGU_PENGEMBALIAN => 'Buku ini sedang dalam proses pengembalian. Silakan selesaikan pengembalian dengan scan barcode ke petugas sebelum meminjam kembali.',
                Loan::STATUS_MENUNGGU_KONFIRMASI  => 'Anda sudah memiliki pengajuan peminjaman untuk buku ini yang menunggu konfirmasi barcode.',
                default                           => 'Anda masih meminjam buku ini. Harap kembalikan dan scan barcode ke petugas sebelum meminjam kembali.',
            };
            return back()->withErrors(['book_id' => $msg]);
        }

        // Create the loan with status "menunggu_konfirmasi"
        $loan = Loan::create([
            'user_id'  => Auth::id(),
            'book_id'  => $validated['book_id'],
            'loan_date' => now()->toDateString(),
            'due_date'  => $validated['due_date'],
            'status'   => Loan::STATUS_MENUNGGU_KONFIRMASI,
        ]);

        // Reduce book stock immediately upon request
        $book->decrement('stock');

        // Generate loan barcode
        $loan->generateLoanBarcode();

        return redirect()->route('my-loans')
            ->with('success', 'Pengajuan berhasil! Tunjukkan barcode kepada petugas untuk mengambil buku.');
    }

    /**
     * User requests book return — generates return barcode.
     */
    public function requestReturn(Loan $loan)
    {
        // Ensure this loan belongs to the authenticated user
        if ($loan->user_id !== Auth::id()) {
            abort(403);
        }

        // Only allow return request for active loans
        $allowedStatuses = [Loan::STATUS_PEMINJAMAN, Loan::STATUS_TERLAMBAT];
        if (!in_array($loan->status, $allowedStatuses)) {
            return redirect()->route('my-loans')->with('error', 'Peminjaman ini tidak dapat dikembalikan saat ini.');
        }

        // Generate return barcode
        $loan->generateReturnBarcode();

        // Update status to waiting for return confirmation
        $loan->update(['status' => Loan::STATUS_MENUNGGU_PENGEMBALIAN]);

        return redirect()->route('my-loans')
            ->with('success', 'Barcode pengembalian dibuat! Tunjukkan kepada petugas untuk mengembalikan buku.');
    }

    /**
     * Cancel a pending loan (status menunggu_konfirmasi only).
     * Restores book stock.
     */
    public function cancelLoan(Loan $loan)
    {
        if ($loan->user_id !== Auth::id()) {
            abort(403);
        }

        if ($loan->status !== Loan::STATUS_MENUNGGU_KONFIRMASI) {
            return redirect()->route('my-loans')->with('error', 'Pengajuan ini tidak dapat dibatalkan.');
        }

        // Restore stock
        $loan->book->increment('stock');

        $loan->update(['status' => Loan::STATUS_EXPIRED]);

        return redirect()->route('my-loans')->with('success', 'Pengajuan peminjaman berhasil dibatalkan.');
    }

    /**
     * Cancel a pending return request (status menunggu_pengembalian).
     * Returns loan back to peminjaman/terlambat status.
     */
    public function cancelReturn(Loan $loan)
    {
        if ($loan->user_id !== Auth::id()) {
            abort(403);
        }

        if ($loan->status !== Loan::STATUS_MENUNGGU_PENGEMBALIAN) {
            return redirect()->route('my-loans')->with('error', 'Tidak ada permintaan pengembalian yang aktif.');
        }

        // Determine correct status (peminjaman or terlambat)
        $newStatus = $loan->due_date->isBefore(today())
            ? Loan::STATUS_TERLAMBAT
            : Loan::STATUS_PEMINJAMAN;

        $loan->update([
            'status'                   => $newStatus,
            'return_barcode'           => null,
            'return_barcode_expires_at' => null,
        ]);

        return redirect()->route('my-loans')->with('success', 'Permintaan pengembalian dibatalkan.');
    }

    /**
     * Display the specified loan (admin).
     */
    public function adminShow(Loan $loan)
    {
        $loan->load('user', 'book');
        return view('admin.loans.show', compact('loan'));
    }

    /**
     * Show the form for editing the specified loan (admin).
     */
    public function adminEdit(Loan $loan)
    {
        $loan->load('user', 'book');
        $users = User::where('role', 'pengunjung')->get();
        $books = Book::get();

        return view('admin.loans.edit', compact('loan', 'users', 'books'));
    }

    /**
     * Update the specified loan in database (admin).
     */
    public function adminUpdate(Request $request, Loan $loan)
    {
        $validated = $request->validate([
            'loan_date'   => 'required|date',
            'due_date'    => 'required|date|after_or_equal:loan_date',
            'return_date' => 'nullable|date|after_or_equal:loan_date',
            'notes'       => 'nullable|string',
        ]);

        $oldBook   = $loan->book_id;
        $oldStatus = $loan->status;

        // Determine status
        if (!empty($validated['return_date'])) {
            $status = Loan::STATUS_DIKEMBALIKAN;
        } else {
            $dueDate = Carbon::parse($validated['due_date']);
            if ($dueDate->isBefore(today())) {
                $status = Loan::STATUS_TERLAMBAT;
            } else {
                $status = Loan::STATUS_PEMINJAMAN;
            }
        }

        $wasReturned = !empty($loan->return_date);
        $nowReturned = !empty($validated['return_date']);

        $validated['status'] = $status;
        $loan->update($validated);

        // Handle stock if book changed
        if ($loan->book_id !== $oldBook) {
            $oldBookObj = Book::find($oldBook);
            $newBookObj = $loan->book;
            $oldBookObj->increment('stock');
            $newBookObj->decrement('stock');
        }

        // Increment stock if returned (was not returned before)
        $wasActive = in_array($oldStatus, [Loan::STATUS_PEMINJAMAN, Loan::STATUS_TERLAMBAT, Loan::STATUS_MENUNGGU_PENGEMBALIAN, Loan::STATUS_MENUNGGU_KONFIRMASI]);
        if ($wasActive && !$wasReturned && $nowReturned) {
            $loan->book?->increment('stock');
        }

        // Decrement stock if un-returned (was returned before but return_date is now cleared)
        if ($wasReturned && !$nowReturned) {
            $loan->book?->decrement('stock');
        }

        return redirect()->route('admin.loans.index')
            ->with('success', 'Peminjaman berhasil diperbarui');
    }

    /**
     * Remove the specified loan from database (admin).
     */
    public function adminDestroy(Loan $loan)
    {
        // Restore stock if loan was still active (not returned, not expired)
        if (in_array($loan->status, Loan::activeStatuses())) {
            $loan->book->increment('stock');
        }

        $loan->delete();

        return redirect()->route('admin.loans.index')
            ->with('success', 'Peminjaman berhasil dihapus');
    }

    /**
     * Auto-expire barcodes that have passed their expiry time.
     * - Loan barcodes: restore stock, set status to expired
     * - Return barcodes: revert status to peminjaman/terlambat
     */
    private function autoExpireBarcodes(): void
    {
        // Expire loan barcodes (menunggu_konfirmasi that timed out)
        $expiredLoans = Loan::where('status', Loan::STATUS_MENUNGGU_KONFIRMASI)
            ->whereNotNull('loan_barcode_expires_at')
            ->where('loan_barcode_expires_at', '<', now())
            ->get();

        foreach ($expiredLoans as $loan) {
            // Restore stock since the borrow never happened
            $loan->book->increment('stock');
            $loan->update(['status' => Loan::STATUS_EXPIRED]);
        }

        // Expire return barcodes (menunggu_pengembalian that timed out)
        $expiredReturns = Loan::where('status', Loan::STATUS_MENUNGGU_PENGEMBALIAN)
            ->whereNotNull('return_barcode_expires_at')
            ->where('return_barcode_expires_at', '<', now())
            ->get();

        foreach ($expiredReturns as $loan) {
            // Revert to active status
            $newStatus = $loan->due_date->isBefore(today())
                ? Loan::STATUS_TERLAMBAT
                : Loan::STATUS_PEMINJAMAN;

            $loan->update([
                'status'                    => $newStatus,
                'return_barcode'            => null,
                'return_barcode_expires_at' => null,
            ]);
        }
    }

    /**
     * Return pure SVG response for loan or return barcode
     */
    public function barcodeSvg(Loan $loan, string $type)
    {
        if ($loan->user_id !== Auth::id()) {
            abort(403);
        }

        $svg = $type === 'loan' ? $loan->getLoanQrCodeSvg(200) : $loan->getReturnQrCodeSvg(200);

        if (!$svg) {
            abort(404);
        }

        return response($svg, 200)->header('Content-Type', 'image/svg+xml');
    }
}
