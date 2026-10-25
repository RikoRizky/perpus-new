<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\User;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /**
     * Display the admin dashboard with real-time data.
     */
    public function index()
    {
        // 1. Auto-expire peminjaman yang barcode-nya sudah lewat waktu
        $expiredLoans = Loan::where('status', Loan::STATUS_MENUNGGU_KONFIRMASI)
            ->whereNotNull('loan_barcode_expires_at')
            ->where('loan_barcode_expires_at', '<', now())
            ->get();

        foreach ($expiredLoans as $loan) {
            $loan->book?->increment('stock');
            $loan->update(['status' => Loan::STATUS_EXPIRED]);
        }

        // 2. Auto-expire return barcodes yang lewat waktu
        $expiredReturns = Loan::where('status', Loan::STATUS_MENUNGGU_PENGEMBALIAN)
            ->whereNotNull('return_barcode_expires_at')
            ->where('return_barcode_expires_at', '<', now())
            ->get();

        foreach ($expiredReturns as $loan) {
            $newStatus = ($loan->due_date && $loan->due_date->isBefore(today()))
                ? Loan::STATUS_TERLAMBAT
                : Loan::STATUS_PEMINJAMAN;
            $loan->update([
                'status'                    => $newStatus,
                'return_barcode'            => null,
                'return_barcode_expires_at' => null,
            ]);
        }

        // 3. Auto-update peminjaman aktif yang sudah melewati jatuh tempo menjadi terlambat
        Loan::where('status', Loan::STATUS_PEMINJAMAN)
            ->whereNull('return_date')
            ->where('due_date', '<', today())
            ->update(['status' => Loan::STATUS_TERLAMBAT]);

        // 4. Pastikan peminjaman yang sudah ada tanggal pengembaliannya berstatus dikembalikan
        Loan::whereNotNull('return_date')
            ->where('status', '!=', Loan::STATUS_DIKEMBALIKAN)
            ->update(['status' => Loan::STATUS_DIKEMBALIKAN]);

        // Get counts for stat cards
        $totalBooks = Book::count();
        $totalUsers = User::count();
        $activeLoans = Loan::whereIn('status', [Loan::STATUS_PEMINJAMAN, Loan::STATUS_MENUNGGU_PENGEMBALIAN])->whereNull('return_date')->count();
        $overdueLoans = Loan::where('status', Loan::STATUS_TERLAMBAT)->whereNull('return_date')->count();

        // Get recent loans (latest 5) - diurutkan berdasarkan updated_at agar data terbaru muncul di atas
        $recentLoans = Loan::with(['user', 'book'])
            ->orderBy('updated_at', 'desc')
            ->take(5)
            ->get();

        // Get overdue loans (5 loans) - yang masih dipinjam dan berstatus terlambat
        $overdueLoansList = Loan::with(['user', 'book'])
            ->where('status', Loan::STATUS_TERLAMBAT)
            ->whereNull('return_date')
            ->orderBy('due_date', 'asc')
            ->take(5)
            ->get();

        // Get currently borrowed books (5 loans) - yang masih aktif dipinjam
        $borrowedBooks = Loan::with(['user', 'book'])
            ->where('status', Loan::STATUS_PEMINJAMAN)
            ->whereNull('return_date')
            ->orderBy('due_date', 'asc')
            ->take(5)
            ->get();

        // Get returned/completed loans (5 loans)
        $returnedBooks = Loan::with(['user', 'book'])
            ->where(function($q) {
                $q->where('status', Loan::STATUS_DIKEMBALIKAN)
                  ->orWhereNotNull('return_date');
            })
            ->orderBy('return_date', 'desc')
            ->take(5)
            ->get();

        return view('admin', compact(
            'totalBooks',
            'totalUsers',
            'activeLoans',
            'overdueLoans',
            'recentLoans',
            'overdueLoansList',
            'borrowedBooks',
            'returnedBooks'
        ));
    }
}

