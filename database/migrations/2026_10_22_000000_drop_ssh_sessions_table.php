<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tabela ssh_sessions nunca foi usada pelo painel: o estado do túnel fica
     * em connection_requests.
     */
    public function up(): void
    {
        Schema::dropIfExists('ssh_sessions');
    }

    public function down(): void
    {
        Schema::create('ssh_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_request_id')->constrained()->onDelete('cascade');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->enum('status', ['active', 'closed'])->default('active');
            $table->timestamps();
        });
    }
};
