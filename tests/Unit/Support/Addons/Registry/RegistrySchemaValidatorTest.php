<?php

namespace Tests\Unit\Support\Addons\Registry;

use App\Support\Addons\Registry\RegistryItem;
use App\Support\Addons\Registry\RegistrySchemaValidator;
use Tests\TestCase;

class RegistrySchemaValidatorTest extends TestCase
{
    private function document(array $items = []): array
    {
        return ['registry' => ['name' => 'ALTA', 'version' => 'build-x', 'application_version' => '1.0.0', 'build_version' => 'build-x', 'schema_version' => '1', 'generated_at' => '1970-01-01T00:00:00+00:00', 'additive' => true], 'items' => $items];
    }

    private function item(): array
    {
        return ['code' => 'core.demo', 'type' => 'extension', 'vendor' => 'Core', 'name' => 'Demo', 'description' => '', 'version' => '1.2.3', 'category' => null, 'tags' => ['demo'], 'requires_platform' => '^1.0', 'dependencies' => [['code' => 'core.base', 'constraint' => '^1', 'required' => true]], 'is_featured' => false, 'homepage_url' => 'https://example.test', 'documentation_url' => null, 'publisher' => ['public_id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Publisher'], 'published_at' => '2026-07-14T00:00:00+00:00', 'artifact' => ['url' => 'https://registry.example.test/api/v1/artifacts/11111111-1111-4111-8111-111111111111/download', 'type' => 'zip', 'sha256' => str_repeat('a', 64), 'size' => 123, 'signature' => ['type' => 'ed25519', 'value' => base64_encode('signature'), 'key_id' => 'key-1', 'payload_version' => 'raw-zip-v1']], 'unknown' => 'accepted'];
    }

    public function test_valid_empty_epoch_and_complete_item_are_accepted_with_typed_fields(): void
    {
        $validator = new RegistrySchemaValidator;
        $this->assertTrue($validator->validate($this->document())['valid']);
        $result = $validator->validate($this->document([$this->item()]), ['downloads' => ['max_size' => 1000]]);
        $this->assertTrue($result['valid']);
        $item = RegistryItem::fromArray($result['document']['items'][0]);
        $this->assertSame('Publisher', $item->publisher['name']);
        $this->assertTrue($item->dependencies[0]['required']);
        $this->assertSame('raw-zip-v1', $item->artifact['signature']['payload_version']);
    }

    public function test_wrong_schema_malformed_security_fields_and_missing_header_are_rejected(): void
    {
        $validator = new RegistrySchemaValidator;
        $wrong = $this->document();
        $wrong['registry']['schema_version'] = '2';
        $this->assertFalse($validator->validate($wrong)['valid']);
        $bad = $this->document([$this->item()]);
        unset($bad['registry']['build_version']);
        $bad['items'][0]['artifact']['signature']['payload_version'] = 'other';
        $bad['items'][0]['artifact']['sha256'] = 'ABC';
        $this->assertFalse($validator->validate($bad, ['downloads' => ['max_size' => 1000]])['valid']);
    }
}
