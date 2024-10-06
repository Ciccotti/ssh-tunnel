<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateConnectionRequestsTable extends Migration
{
    public function up()
    {
        Schema::create('connection_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Usuário que solicitou a conexão
            $table->integer('service_port'); // Porta que o usuário deseja acessar (ex.: 3389 para RDP)
            $table->integer('server_port'); // Porta fornecida pelo servidor para o túnel SSH
            $table->string('ip_address'); // Endereço IP externo do usuário que solicitou a conexão
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending'); // Status do pedido
            $table->timestamp('requested_at')->useCurrent(); // Quando o pedido foi feito
            $table->timestamp('completed_at')->nullable(); // Quando o túnel foi estabelecido
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('connection_requests');
    }
}
