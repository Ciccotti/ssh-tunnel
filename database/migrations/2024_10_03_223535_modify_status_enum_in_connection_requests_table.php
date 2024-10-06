<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ModifyStatusEnumInConnectionRequestsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('connection_requests', function (Blueprint $table) {
            // Alterar a coluna 'status' para incluir 'in_progress'
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('connection_requests', function (Blueprint $table) {
            // Reverter a coluna 'status' para remover 'in_progress'
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending')->change();
        });
    }
}

