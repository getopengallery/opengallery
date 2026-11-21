<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The test mailbox (Admin → Settings → E-mail → "Test mailbox"), which
 * replaces "Do not send (write to the log file)": e-mails are kept here
 * instead of being sent, and super administrators read them in the admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_mails', function (Blueprint $table) {
            $table->id();
            $table->string('from')->nullable();
            $table->text('to');
            $table->string('subject')->nullable();
            $table->longText('html')->nullable();
            $table->longText('text')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        // Sites that chose "write to the log file" keep testing, now in the mailbox.
        if (Setting::get('mail.delivery') === 'log') {
            Setting::put('mail.delivery', 'mailbox');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('test_mails');

        if (Setting::get('mail.delivery') === 'mailbox') {
            Setting::put('mail.delivery', 'log');
        }
    }
};
