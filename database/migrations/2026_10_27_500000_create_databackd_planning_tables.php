<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AFV MVP (S5): budgets, budget lines and saved forecasts
return new class extends Migration {

    public function up()
    {
        Schema::create('fin_budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('name');
            $table->unsignedSmallInteger('year');
            $table->enum('version', ['draft', 'approved'])->default('draft');
            $table->enum('scenario', ['base', 'optimistic', 'pessimistic'])->default('base');
            $table->string('currency', 3)->default('EUR');
            $table->text('notes')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'year']);
            $table->index('entity_id');
        });

        Schema::create('fin_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->unsignedBigInteger('budget_id');
            $table->foreign('budget_id')->references('id')->on('fin_budgets')->onDelete('cascade')->onUpdate('cascade');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('region_id')->nullable();
            $table->unsignedBigInteger('product_group_id')->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->unsignedTinyInteger('month');
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();

            // NULL dimensions are distinct in a MySQL unique index, so the editor/factory must upsert by hand
            $table->unique(['budget_id', 'account_id', 'region_id', 'product_group_id', 'cost_center_id', 'month'], 'fin_budget_lines_unique');
            $table->index(['budget_id', 'account_id']);
        });

        Schema::create('fin_forecasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->string('name');
            $table->string('metric', 40);
            $table->string('metric_ref', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->enum('method', ['linear', 'moving_average', 'seasonal', 'manual'])->default('linear');
            $table->json('params')->nullable();
            $table->unsignedTinyInteger('horizon')->default(6);
            $table->json('scenarios')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('company_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fin_forecasts');
        Schema::dropIfExists('fin_budget_lines');
        Schema::dropIfExists('fin_budgets');
    }

};
