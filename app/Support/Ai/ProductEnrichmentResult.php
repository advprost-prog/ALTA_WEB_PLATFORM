<?php

namespace App\Support\Ai;

class ProductEnrichmentResult
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => ['string', 'null']],
                'short_description' => ['type' => ['string', 'null']],
                'full_description' => ['type' => ['string', 'null']],
                'seo_title' => ['type' => ['string', 'null']],
                'seo_description' => ['type' => ['string', 'null']],
                'attributes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'unit' => ['type' => ['string', 'null']],
                            'sort_order' => ['type' => ['integer', 'null']],
                        ],
                        'required' => ['name', 'value', 'unit', 'sort_order'],
                    ],
                ],
                'gtin_candidates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'gtin' => ['type' => 'string'],
                            'source' => ['type' => ['string', 'null']],
                            'confidence' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                            'note' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['gtin', 'source', 'confidence', 'note'],
                    ],
                ],
                'image_alt_text' => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => [
                'name',
                'short_description',
                'full_description',
                'seo_title',
                'seo_description',
                'attributes',
                'gtin_candidates',
                'image_alt_text',
                'confidence',
                'warnings',
            ],
        ];
    }
}
