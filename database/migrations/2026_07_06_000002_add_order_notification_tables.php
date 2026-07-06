<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('event')->index();
            $table->string('channel')->index();
            $table->string('name');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(false)->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event', 'channel', 'is_active']);
        });

        Schema::create('notification_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event')->nullable()->index();
            $table->string('channel')->nullable()->index();
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'event', 'channel']);
            $table->index(['status', 'created_at']);
        });

        $this->seedTemplates();
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('notification_templates');
    }

    private function seedTemplates(): void
    {
        $now = now();
        $templates = [
            [
                'code' => 'order_created.email',
                'event' => 'order_created',
                'name' => 'Замовлення створено',
                'subject' => 'Замовлення {{ order.number }} прийнято',
                'body' => "Вітаємо, {{ order.customer_name }}!\n\nМи отримали ваше замовлення {{ order.number }} на суму {{ order.total }} {{ order.currency }}.\nСпосіб оплати: {{ order.payment_method }}.\nСпосіб доставки: {{ order.delivery_method }}.\n\nМи повідомимо вас про наступні зміни статусу.",
                'sort_order' => 10,
            ],
            [
                'code' => 'order_confirmed.email',
                'event' => 'order_confirmed',
                'name' => 'Замовлення підтверджено',
                'subject' => 'Замовлення {{ order.number }} підтверджено',
                'body' => "Вітаємо, {{ order.customer_name }}!\n\nВаше замовлення {{ order.number }} підтверджено менеджером.\nСума: {{ order.total }} {{ order.currency }}.\nСтатус оплати: {{ order.payment_status }}.",
                'sort_order' => 20,
            ],
            [
                'code' => 'order_processing.email',
                'event' => 'order_processing',
                'name' => 'Замовлення в обробці',
                'subject' => 'Замовлення {{ order.number }} в обробці',
                'body' => "Ваше замовлення {{ order.number }} передано в обробку.\n\nМи готуємо позиції до відправки або видачі.",
                'sort_order' => 30,
            ],
            [
                'code' => 'order_ready_to_ship.email',
                'event' => 'order_ready_to_ship',
                'name' => 'Замовлення готове до відправки',
                'subject' => 'Замовлення {{ order.number }} готове до відправки',
                'body' => "Замовлення {{ order.number }} готове до відправки.\n\nСпосіб доставки: {{ order.delivery_method }}.",
                'sort_order' => 40,
            ],
            [
                'code' => 'order_shipped.email',
                'event' => 'order_shipped',
                'name' => 'Замовлення відправлено',
                'subject' => 'Замовлення {{ order.number }} відправлено',
                'body' => "Замовлення {{ order.number }} відправлено.\n\nСтатус доставки: {{ order.delivery_status }}.",
                'sort_order' => 50,
            ],
            [
                'code' => 'order_completed.email',
                'event' => 'order_completed',
                'name' => 'Замовлення завершено',
                'subject' => 'Замовлення {{ order.number }} завершено',
                'body' => "Дякуємо!\n\nЗамовлення {{ order.number }} завершено.",
                'sort_order' => 60,
            ],
            [
                'code' => 'order_cancelled.email',
                'event' => 'order_cancelled',
                'name' => 'Замовлення скасовано',
                'subject' => 'Замовлення {{ order.number }} скасовано',
                'body' => "Замовлення {{ order.number }} скасовано.\n\nПричина: {{ cancel_reason }}",
                'sort_order' => 70,
            ],
            [
                'code' => 'payment_paid.email',
                'event' => 'payment_paid',
                'name' => 'Оплату отримано',
                'subject' => 'Оплату за замовлення {{ order.number }} отримано',
                'body' => "Оплату за замовлення {{ order.number }} отримано.\n\nСума замовлення: {{ order.total }} {{ order.currency }}.",
                'sort_order' => 80,
            ],
        ];

        foreach ($templates as $template) {
            DB::table('notification_templates')->updateOrInsert(
                ['code' => $template['code']],
                $template + [
                    'channel' => 'email',
                    'is_active' => true,
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
};
