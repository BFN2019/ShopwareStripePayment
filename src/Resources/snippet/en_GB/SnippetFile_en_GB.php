<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Resources\snippet\en_GB;

use Shopware\Core\Framework\Snippet\Files\SnippetFileInterface;

class SnippetFile_en_GB implements SnippetFileInterface
{
    public function getName(): string
    {
        return 'stripe-payment.en-GB';
    }

    public function getPath(): string
    {
        return __DIR__ . '/stripe-payment.en-GB.json';
    }

    public function getIso(): string
    {
        return 'en-GB';
    }

    public function getAuthor(): string
    {
        return 'Pickware GmbH';
    }

    public function isBase(): bool
    {
        return false;
    }
}
