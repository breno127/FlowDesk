<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('document', 32)->nullable()->unique()->comment('CNPJ/CPF');
            $table->string('domain')->nullable()->index()->comment('Domínio de e-mail para auto-vincular contatos');
            $table->string('tier', 32)->nullable()->comment('Segmento do cliente (ex.: enterprise)');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable()->index();
            $table->string('phone', 32)->nullable()->comment('E.164');
            $table->string('whatsapp_id', 32)->nullable()->unique()->comment('wa_id da Cloud API');
            $table->json('external_ids')->nullable()->comment('IDs em sistemas externos (jira, crm...)');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('client_companies');
    }
};
