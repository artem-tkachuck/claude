<?php

if (file_exists(dirname(__DIR__) . '/var/cache/prod/App_KernelProdContainer.preload.php')) {
    require dirname(__DIR__) . '/var/cache/prod/App_KernelProdContainer.preload.php';
}

// Preload frequently used classes for better performance
if (function_exists('opcache_preload')) {
    $preloadClasses = [
        // Symfony core classes
        'Symfony\Component\HttpKernel\Kernel',
        'Symfony\Component\HttpFoundation\Request',
        'Symfony\Component\HttpFoundation\Response',
        'Symfony\Component\Routing\Router',
        'Symfony\Component\DependencyInjection\Container',

        // Doctrine classes
        'Doctrine\ORM\EntityManager',
        'Doctrine\DBAL\Connection',

        // Application entities
        'App\Entity\User',
        'App\Entity\Deposit',
        'App\Entity\Withdrawal',
        'App\Entity\Transaction',
        'App\Entity\Bonus',

        // Application services
        'App\Service\Telegram\TelegramBotService',
        'App\Service\Blockchain\TronService',
        'App\Service\Security\EncryptionService',
        'App\Service\Bonus\BonusCalculator',
        'App\Service\Transaction\TransactionService',

        // Application repositories
        'App\Repository\UserRepository',
        'App\Repository\DepositRepository',
        'App\Repository\WithdrawalRepository',
        'App\Repository\TransactionRepository',
    ];

    foreach ($preloadClasses as $class) {
        if (class_exists($class)) {
            opcache_compile_file((new ReflectionClass($class))->getFileName());
        }
    }
}