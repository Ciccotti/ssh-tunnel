<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSshSessionsTable extends Migration
{
    public function up()
    {
        Schema::create('ssh_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_request_id')->constrained()->onDelete('cascade');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->enum('status', ['active', 'closed'])->default('active'); // Status da sessão
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ssh_sessions');
    }
}
