<?php

declare(strict_types=1);

namespace OdxProxy\Model;

class KeywordRequest implements \JsonSerializable
{
    public ?array $fields = null;
    public ?string $order = null;
    public ?int $limit = null;
    public ?int $offset = null;
    public ?array $context = null;

    // Fluid setters for ease of use
    public function setFields(array $fields): self { $this->fields = $fields; return $this; }
    public function setOrder(string $order): self { $this->order = $order; return $this; }
    public function setLimit(int $limit): self { $this->limit = $limit; return $this; }
    public function setOffset(int $offset): self { $this->offset = $offset; return $this; }
    public function setContext(array $context): self { $this->context = $context; return $this;}
    
    // PHP doesn't have data class copy(), so we manually clone for pagination reset
    public function resetPagination(): self
    {
        $clone = clone $this;
        $clone->order = null;
        $clone->limit = null;
        $clone->offset = null;
        $clone->fields = null;
        return $clone;
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'fields' => $this->fields,
            'order' => $this->order,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'context' => $this->context,
        ], fn($v) => $v !== null);
    }
}