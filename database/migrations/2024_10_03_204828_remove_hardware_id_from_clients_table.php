<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveHardwareIdFromClientsTable extends Migration
{
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // O índice único precisa sair antes da coluna (exigido pelo SQLite).
            $table->dropUnique(['hardware_id']);
            $table->dropColumn('hardware_id');
        });
    }

    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('hardware_id')->unique();
        });
    }
}

