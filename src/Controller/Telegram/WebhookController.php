<?php

namespace App\Controller\Telegram;

use App\Service\Telegram\TelegramBotService;
use Exception;
use Longman\TelegramBot\Entities\Update;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class WebhookController extends AbstractController
{
    #[Route('/telegram/webhook/{token}', name: 'telegram_webhook', methods: ['POST'])]
    public function webhook(
        Request            $request,
        string             $token,
        TelegramBotService $telegramService,
        LoggerInterface    $logger
    ): Response
    {
        // Verify webhook token
        if ($token !== $_ENV['TELEGRAM_WEBHOOK_SECRET']) {
            $logger->warning('Invalid webhook token', ['ip' => $request->getClientIp()]);
            return new Response('Unauthorized', 401);
        }

        // Verify signature
        $payload = $request->getContent();
        $signature = $request->headers->get('X-Telegram-Bot-Api-Secret-Token');

        if (!$telegramService->verifyWebhookSignature($payload, $signature)) {
            $logger->warning('Invalid webhook signature', ['ip' => $request->getClientIp()]);
            return new Response('Invalid signature', 401);
        }

        try {
            $update = json_decode($payload, true);
            $telegramService->processUpdate(new Update($update));

            return new Response('OK');
        } catch (Exception $e) {
            $logger->error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new Response('Internal error', 500);
        }
    }
}