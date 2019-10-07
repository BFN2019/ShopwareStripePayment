<?php declare(strict_types=1);

namespace Stripe\ShopwarePlugin\Resources\snippet\de_DE;

use Shopware\Core\Framework\Snippet\Files\SnippetFileInterface;

class SnippetFile_de_DE implements SnippetFileInterface
{
    public function getName(): string
    {
        return 'stripe-payment.de-DE';
    }

    public function getPath(): string
    {
        return __DIR__ . '/stripe-payment.de-DE.json';
    }

    public function getIso(): string
    {
        return 'de-DE';
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
