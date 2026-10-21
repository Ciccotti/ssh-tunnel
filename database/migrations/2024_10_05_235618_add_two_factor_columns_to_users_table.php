<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Duplicata de 2024_09_26_190446_add_two_factor_columns_to_users_table, que roda antes desta e já
 * faz a alteração. Mantida vazia só para não quebrar bancos que já registraram
 * esta migration na tabela "migrations".
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
