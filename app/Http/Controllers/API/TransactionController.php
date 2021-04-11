<?php

namespace App\Http\Controllers\API;

use App\Helpers\ResponseFormatter;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Midtrans\Config;
use Midtrans\Snap;

class TransactionController extends Controller
{
    public function all(Request $request)
    {
        $id = $request->input('id');
        $limit = $request->input('limit', 6);
        $food_id = $request->input('food_id');
        $status = $request->input('status');
 
        if ($id) {
            $transaction = Transaction::with(['food', 'user'])->find($id);
            if ($transaction) {
                return ResponseFormatter::success(
                    $transaction,
                    'Data transaction berhasil diambil'
                );
            } else {
                return ResponseFormatter::error(
                    null,
                    'Data transaction tidak ditemukan',
                    404
                );
            }
        }

        $transaction = Transaction::with(['food','user'])->where('id_user', Auth::user()->id);

        if ($food_id) {
            $transaction->where('food_id', $food_id);
        }

        if ($status) {
            $transaction->where('status', $status);
        }

        return ResponseFormatter::success(
            $transaction->paginate($limit),
            'Data list transaksi berhasil diambil'
        );
    }

    public function update(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);

        $transaction->update($request->all());

        return ResponseFormatter::success($transaction, 'Transaksi berhasil diperbaharui');
    }

    public function checkout(Request $request) {
        $request->validate([
            'food_id' => 'required|exists:food,id',
            'user_id' => 'required|exists:users,id',
            'quantity' => 'required',
            'total' => 'required',
            'status' => 'required',
        ]);

        $transaction = Transaction::create([
            'food_id' => 'food_id',
            'user_id' => 'user_id',
            'quantity' => 'quantity',
            'total' => 'total',
            'status' => 'status',
            'payment_url' => "",
        ]);

        // Konfigurasi midtrans
        Config::$serverKey = config('services.midtrans.serverKey');
        Config::$isProduction = config('services.midtrans.isProduction');
        Config::$isSanitized = config('services.midtrans.isSanitized');
        Config::$is3ds = config('services.midtrans.is3ds');

        // Panggil transaksi yang telah dibuat
        $transaction = Transaction::with(['food', 'user'])->find($transaction->id);

        // Membuat transaksi midtrans
        $midtrans = [
            'transaction_details' => [
                'order_id' => $transaction->id,
                'gross_amount' => (int) $transaction->total,
            ],
            'customer_details' => [
                'frist_name' => $transaction->user->name,
                'email' => $transaction->user->email,
            ],
            'enabled_payments' => ['gopay, bank_transfer'],
            'vtweb' => [],
        ];

        // Memanggil Midtrans
        try {
            // Ambil halaman payment url
            $paymentUrl = Snap::createTransaction($midtrans)->redirect_url;

            $transaction->payment_url = $paymentUrl;
            $transaction->save();
         
            // Mengembalikan Data ke API
            return ResponseFormatter::success($transaction, 'Transaksi berhasil');
        } catch (Exception $e) {
            return ResponseFormatter::error($e->getMessage(), 'Transaksi gagal');
        }

    }
}
