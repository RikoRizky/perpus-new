<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanReturnFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $pengunjung;
    private User $petugas;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pengunjung = User::factory()->create([
            'role' => User::ROLE_PENGUNJUNG,
            'name' => 'Riko Rizky',
            'npm'  => '12345678',
        ]);

        $this->petugas = User::factory()->create([
            'role' => User::ROLE_PETUGAS,
            'name' => 'Petugas Perpustakaan',
            'npm'  => 'PTG001',
        ]);

        $this->book = Book::create([
            'isbn'           => '978-0-7475-3269-9',
            'title'          => 'Harry Potter and the Philosopher\'s Stone',
            'author'         => 'J.K. Rowling',
            'publisher'      => 'Bloomsbury',
            'stock'          => 5,
            'published_date' => '1997-06-26',
        ]);
    }

    /**
     * Test that when a loan is active, publicShow displays 'Sudah Dipinjam'.
     */
    public function test_book_show_displays_sudah_dipinjam_when_actively_borrowed(): void
    {
        Loan::create([
            'user_id'   => $this->pengunjung->id,
            'book_id'   => $this->book->id,
            'loan_date' => now()->subDays(2)->toDateString(),
            'due_date'  => now()->addDays(5)->toDateString(),
            'status'    => Loan::STATUS_PEMINJAMAN,
        ]);

        $response = $this->actingAs($this->pengunjung)->get(route('books.show', $this->book->id));

        $response->assertStatus(200);
        $response->assertSee('Sudah Dipinjam');
        $response->assertDontSee('Ajukan Peminjaman');
    }

    /**
     * Test that when user clicks request return, the book show page STILL considers the loan active
     * (Menunggu Scan Pengembalian / Sudah Dipinjam) and does NOT allow borrowing again until scanned.
     */
    public function test_book_cannot_be_borrowed_after_request_return_until_scanned(): void
    {
        $loan = Loan::create([
            'user_id'   => $this->pengunjung->id,
            'book_id'   => $this->book->id,
            'loan_date' => now()->subDays(10)->toDateString(),
            'due_date'  => now()->subDays(3)->toDateString(),
            'status'    => Loan::STATUS_TERLAMBAT,
        ]);

        // 1. User clicks "Kembalikan Buku"
        $returnResponse = $this->actingAs($this->pengunjung)->post(route('loans.request-return', $loan));
        $returnResponse->assertRedirect(route('my-loans'));

        $loan->refresh();
        $this->assertEquals(Loan::STATUS_MENUNGGU_PENGEMBALIAN, $loan->status);
        $this->assertNotNull($loan->return_barcode);

        // 2. Visit book show page: MUST NOT show "Ajukan Peminjaman", must show "Sudah Dipinjam", "Lihat Barcode", and "Lihat Semua Riwayat Pinjaman"
        $showResponse = $this->actingAs($this->pengunjung)->get(route('books.show', $this->book->id));
        $showResponse->assertStatus(200);
        $showResponse->assertSee('Sudah Dipinjam');
        $showResponse->assertDontSee('(Menunggu Scan Pengembalian)');
        $showResponse->assertSee('Lihat Barcode');
        $showResponse->assertSee('Lihat Semua Riwayat Pinjaman');
        $showResponse->assertDontSee('Ajukan Peminjaman');

        // Test barcode SVG delivery
        $svgResponse = $this->actingAs($this->pengunjung)->get(route('loans.barcode-svg', ['loan' => $loan->id, 'type' => 'return']));
        $svgResponse->assertStatus(200);
        $svgResponse->assertHeader('Content-Type', 'image/svg+xml');

        // 3. Trying to submit borrow request directly MUST be rejected
        $borrowAttempt = $this->actingAs($this->pengunjung)->post(route('borrow'), [
            'book_id'  => $this->book->id,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        $borrowAttempt->assertSessionHasErrors('book_id');

        // 4. Petugas scans the return barcode
        $scanResponse = $this->actingAs($this->petugas)->post(route('petugas.scan.return'), [
            'token' => $loan->return_barcode,
        ]);
        $scanResponse->assertRedirect(route('petugas.dashboard'));
        $scanResponse->assertSessionHas('scan_success');

        $loan->refresh();
        $this->assertEquals(Loan::STATUS_DIKEMBALIKAN, $loan->status);
        $this->assertNotNull($loan->return_date);

        // 5. After scanning, visit book show page: NOW the user CAN borrow again, and "Lihat Semua Riwayat Pinjaman" is gone
        $showAfterReturn = $this->actingAs($this->pengunjung)->get(route('books.show', $this->book->id));
        $showAfterReturn->assertStatus(200);
        $showAfterReturn->assertSee('Ajukan Peminjaman');
        $showAfterReturn->assertDontSee('Lihat Semua Riwayat Pinjaman');
    }
}
