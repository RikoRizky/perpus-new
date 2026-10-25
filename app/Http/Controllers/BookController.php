<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BookController extends Controller
{
    /**
     * Display a listing of the resource (for admin).
     */
    public function index(Request $request)
    {
        $search   = $request->search   ?? '';
        $category = $request->category ?? '';
        $filter   = $request->filter   ?? ''; // 'low' | 'out'

        $query = Book::query();

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('title',  'like', "%{$search}%")
                  ->orWhere('author', 'like', "%{$search}%")
                  ->orWhere('isbn',   'like', "%{$search}%");
            });
        }

        if ($category) {
            $query->where('category', $category);
        }

        if ($filter === 'low') {
            $query->where('stock', '<=', 3)->where('stock', '>', 0);
        } elseif ($filter === 'out') {
            $query->where('stock', 0);
        }

        $books           = $query->latest()->paginate(10)->withQueryString();
        $categories      = Book::select('category')->distinct()->pluck('category')->filter();
        $lowStockCount   = Book::where('stock', '<=', 3)->where('stock', '>', 0)->count();
        $outOfStockCount = Book::where('stock', 0)->count();

        return view('admin.books.index', compact(
            'books', 'categories', 'search', 'category', 'filter', 'lowStockCount', 'outOfStockCount'
        ));
    }

    /**
     * Display all books for visitors (public home page).
     */
    public function publicIndex(Request $request)
    {
        $search   = $request->search   ?? '';
        $category = $request->category ?? '';

        $query = Book::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title',     'like', "%{$search}%")
                  ->orWhere('author',    'like', "%{$search}%")
                  ->orWhere('isbn',      'like', "%{$search}%")
                  ->orWhere('publisher', 'like', "%{$search}%");
            });
        }

        if ($category) {
            $query->where('category', $category);
        }

        $books      = $query->latest()->paginate(15);
        $categories = Book::select('category')->distinct()->pluck('category')->filter();
        $popularBooks = Book::orderBy('stock', 'desc')->take(5)->get();

        // Buku hits Gen-Z untuk rak 3D interaktif di beranda
        $shelfBooks = Book::where('is_featured', true)
                          ->whereNotNull('image')
                          ->get();

        // Fallback: jika featured < 5, tambah dari semua buku
        if ($shelfBooks->count() < 5) {
            $shelfBooks = Book::whereNotNull('image')->get();
        }

        // Hero section: 3 buku featured secara acak
        $featuredBooks = $shelfBooks->random(min(3, $shelfBooks->count()));

        return view('home', compact(
            'books', 'categories', 'search', 'category',
            'popularBooks', 'featuredBooks', 'shelfBooks'
        ));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.books.form', [
            'book' => new Book(),
            'action' => 'create'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'isbn' => 'required|unique:books,isbn',
            'title' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            'publisher' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:3072',
            'back_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:3072',
            'shelf_number' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'stock' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'language' => 'nullable|string|max:50',
            'published_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $data = $validator->validated();

        // Handle image upload
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageName = time() . '_' . $image->getClientOriginalName();
            $image->move(public_path('images/books'), $imageName);
            $data['image'] = 'images/books/' . $imageName;
        }

        // Handle back image upload
        if ($request->hasFile('back_image')) {
            $backImage = $request->file('back_image');
            $backImageName = 'back_' . time() . '_' . $backImage->getClientOriginalName();
            $backImage->move(public_path('images/books'), $backImageName);
            $data['back_image'] = 'images/books/' . $backImageName;
        }

        Book::create($data);

        return redirect()->route('admin.books.index')
            ->with('success', 'Buku berhasil ditambahkan!');
    }

    /**
     * Display the specified resource (for admin).
     */
    public function show(Book $book)
    {
        return view('admin.books.show', compact('book'));
    }

    /**
     * Display the specified resource for visitors (public).
     */
    public function publicShow(Book $book)
    {
        $userActiveLoan = null;
        $userHasActiveLoan = false;

        if (Auth::check()) {
            $userActiveLoan = Loan::where('user_id', Auth::id())
                ->where('book_id', $book->id)
                ->whereIn('status', Loan::activeStatuses())
                ->latest()
                ->first();

            $userHasActiveLoan = $userActiveLoan !== null;
        }

        return view('books.show', compact('book', 'userHasActiveLoan', 'userActiveLoan'));
    }

    /**
     * Display all books collection for visitors (public).
     */
    public function collection(Request $request)
    {
        $search = $request->search ?? '';
        $category = $request->category ?? '';

        $query = Book::query();

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('author', 'like', "%{$search}%")
                  ->orWhere('isbn', 'like', "%{$search}%")
                  ->orWhere('publisher', 'like', "%{$search}%");
            });
        }

        if ($category) {
            $query->where('category', $category);
        }

        $perPage = $this->getCollectionPerPage($request);
        $books = $query->latest()->paginate($perPage);
        $categories = Book::select('category')->distinct()->pluck('category')->filter();

        return view('books.collection', compact('books', 'categories', 'search', 'category', 'perPage'));
    }

    /**
     * Hitung jumlah item per halaman untuk halaman koleksi:
     * - Mobile (< 768px): 8 buku (2 kolom x 4 baris)
     * - Tablet (768px - 1023px): 16 buku (4 kolom x 4 baris)
     * - Desktop (>= 1024px): 15 buku (5 kolom x 3 baris)
     */
    private function getCollectionPerPage(Request $request): int
    {
        if ($request->filled('per_page')) {
            $val = (int) $request->input('per_page');
            if (in_array($val, [8, 15, 16, 20])) {
                return $val;
            }
        }

        if ($request->hasCookie('device_per_page')) {
            $cookieVal = (int) $request->cookie('device_per_page');
            if (in_array($cookieVal, [8, 15, 16, 20])) {
                return $cookieVal;
            }
        }

        $ua = strtolower($request->header('User-Agent', ''));

        // Cek tablet terlebih dahulu (iPad, Android tablet, atau UA 'tablet')
        $isTablet = str_contains($ua, 'ipad')
            || (str_contains($ua, 'android') && !str_contains($ua, 'mobile'))
            || str_contains($ua, 'tablet')
            || str_contains($ua, 'playbook')
            || str_contains($ua, 'silk');

        if ($isTablet) {
            return 16;
        }

        // Cek mobile (iPhone, Android phone, dsb.)
        $isMobile = str_contains($ua, 'iphone')
            || str_contains($ua, 'ipod')
            || str_contains($ua, 'mobile')
            || str_contains($ua, 'android')
            || str_contains($ua, 'blackberry')
            || str_contains($ua, 'webos');

        if ($isMobile) {
            return 8;
        }

        return 15;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Book $book)
    {
        return view('admin.books.form', [
            'book' => $book,
            'action' => 'edit'
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Book $book)
    {
        $validator = Validator::make($request->all(), [
            'isbn' => 'required|unique:books,isbn,' . $book->id,
            'title' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            'publisher' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:3072',
            'back_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:3072',
            'shelf_number' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'stock' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'language' => 'nullable|string|max:50',
            'published_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $data = $validator->validated();

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($book->image && file_exists(public_path($book->image))) {
                unlink(public_path($book->image));
            }

            $image = $request->file('image');
            $imageName = time() . '_' . $image->getClientOriginalName();
            $image->move(public_path('images/books'), $imageName);
            $data['image'] = 'images/books/' . $imageName;
        }

        // Handle back image upload
        if ($request->hasFile('back_image')) {
            if ($book->back_image && file_exists(public_path($book->back_image))) {
                @unlink(public_path($book->back_image));
            }

            $backImage = $request->file('back_image');
            $backImageName = 'back_' . time() . '_' . $backImage->getClientOriginalName();
            $backImage->move(public_path('images/books'), $backImageName);
            $data['back_image'] = 'images/books/' . $backImageName;
        }

        $book->update($data);

        return redirect()->route('admin.books.index')
            ->with('success', 'Buku berhasil diperbarui!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Book $book)
    {
        // Delete image if exists
        if ($book->image && file_exists(public_path($book->image))) {
            @unlink(public_path($book->image));
        }

        if ($book->back_image && file_exists(public_path($book->back_image))) {
            @unlink(public_path($book->back_image));
        }

        $book->delete();

        return redirect()->route('admin.books.index')
            ->with('success', 'Buku berhasil dihapus!');
    }
}

