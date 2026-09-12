<?php

namespace App\Http\Controllers;

use Dflydev\DotAccessData\Data;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AmoCrmWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // 1. Логируем весь входящий запрос для отладки
        Log::info('AmoCRM Webhook Received:', $request->all());

        // 2. Проверяем, что пришло событие о смене статуса сделки
        if (!$request->has('leads.status')) {
            return response()->json(['status' => 'ignored'], 200);
        }

        // 3. Получаем данные о сделке
        $lead_status = $request->input('leads.status.0');
        $id = $lead_status['id'] ?? null;
        $name = $lead_status['name'] ?? null;
        $status_id = $lead_status['status_id'] ?? null;
        $old_status_id = $lead_status['old_status_id'] ?? null;
        $price = $lead_status['price'] ?? null;
        $price_with_minor_units = $lead_status['price_with_minor_units'] ?? null;
        $responsible_user_id = $lead_status['responsible_user_id'] ?? null;
        $last_modified = $lead_status['last_modified'] ?? null;
        $modified_user_id = $lead_status['modified_user_id'] ?? null;
        $created_user_id = $lead_status['created_user_id'] ?? null;
        $date_create = $lead_status['date_create'] ?? null;
        $pipeline_id = $lead_status['pipeline_id'] ?? null;

        $account_id = $lead_status['account_id'] ?? null;

        $created_at = $lead_status['created_at'] ?? null;
        $updated_at = $lead_status['updated_at'] ?? null;

        // 4. Укажите ID этапа, при переходе на который нужно отправить уведомление
        $targetStatusId = 88517678; // ЗАМЕНИТЕ НА ВАШ ID ЭТАПА

        // Извлекаем кастомные поля в удобный ассоциативный массив
            $custom_fields = $this->parseCustomFields($lead_status['custom_fields'] ?? []);

            // Например, получаем «Ссылка на счет» и «Форма оплаты»
            $invoice_link = $custom_fields['Ссылка на счет'] ?? null;

            $number_provider_order_1 = $custom_fields['1) Номер заказа от поставщика'] ?? null;
            $total_provider_order_1 = (int) ($custom_fields['1) закупка'] ?? null);
            $number_provider_order_2 = $custom_fields['2) Номер заказа от поставщика'] ?? null;
            $total_provider_order_2 = (int) ($custom_fields['2) закупка'] ?? null);
            $number_provider_order_3 = $custom_fields['3) Номер заказа от поставщика'] ?? null;
            $total_provider_order_2 = (int) ($custom_fields['3) закупка'] ?? null);

            $sum_of_delivery = (int) ($custom_fields['Доставка'] ?? null);
            $sum_of_lifting = (int) ($custom_fields['Разгрузка/подъем'] ?? null);

            $date_delivery_pvz = $custom_fields['Дата доставки на ПВЗ'] ?? null;
            $date_delivery_customer = $custom_fields['Дата отгрузки клиенту'] ?? null;

            $delivery_address = $custom_fields['Адрес'] ?? null;

            $payment_form = $custom_fields['Форма оплаты'] ?? null;
            $payment_status = $custom_fields['Оплачено'] ?? null;
            if ($payment_status === 'Онлайн отдел' || $payment_status === 'Форвард') {
                $payment_status = '✅ Оплачено';
            } else {
                $payment_status = '❌ Не оплачено';
            }

            $zakupka = (int) ($custom_fields['Всего закупка'] ?? null);

            $online_department = (int) ($custom_fields['Итого Онлайн отдел'] ?? null);
            $forward = (int) ($custom_fields['Итого Форвард'] ?? null);

            $designer = $custom_fields['Дизайнер'] ?? null;
            $car_driver = $custom_fields['Водитель'] ?? null;

            $date_now = date('Y-m-d H:i:s');

        $message = "📌 *[{$date_now}]!*\n\n"
            . "🆔 *Ссылка на счет:* [{$invoice_link}]\n"
            . "💲 *Статус оплаты:* `{$payment_status}`\n"
            . "🕒 *Дата доставки:* " . $date_delivery_customer;


        // 5. Если сделка перешла на нужный этап — отправляем в Telegram
        if ($status_id == $targetStatusId) {
           $this->sendTelegramNotification($message);
        }

        // 6. Возвращаем успешный ответ, чтобы amoCRM не повторяла запрос
        return response()->json(['status' => 'success'], 200);
    }

    private function sendTelegramNotification($message)
    {
        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (!$botToken || !$chatId) {
            Log::error('Telegram bot token or chat ID is not configured.');
            return;
        }



        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

//        Http::post($url, [
//            'chat_id' => $chatId,
//            'text' => $message,
//            'parse_mode' => 'Markdown',
//        ]);

        $response = Http::timeout(10)
            ->post($url, [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ]);

        Log::info('Telegram response', ['body' => $response->body(), 'status' => $response->status()]);
    }

    /**
     * Преобразует custom_fields в ассоциативный массив [название_поля => значение]
     */
    private function parseCustomFields(array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            if (!$name) {
                continue;
            }

            $values = $field['values'] ?? null;
            if ($values === null) {
                continue;
            }

            // Если values — объект (ассоциативный массив)
            if (is_array($values) && !isset($values[0])) {
                $result[$name] = $values['value'] ?? null;
            }
            // Если values — массив объектов
            elseif (is_array($values)) {
                $vals = [];
                foreach ($values as $item) {
                    if (is_array($item) && isset($item['value'])) {
                        $vals[] = $item['value'];
                    } elseif (is_string($item)) {
                        $vals[] = $item;
                    }
                }
                $result[$name] = implode(', ', $vals);
            } else {
                $result[$name] = $values;
            }
        }

        return $result;
    }

}
