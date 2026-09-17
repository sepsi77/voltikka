<?php

namespace App\Services\ContractInterpretation;

use InvalidArgumentException;

final readonly class ContractInterpretationProfile
{
    public function __construct(
        public string $schemaVersion,
        public string $promptVersion,
        public string $validatorVersion,
        public string $schemaPath,
        public string $promptPath,
        public string $parserVersion = 'canonical-pricing-parser-v1',
    ) {}

    public static function current(): self
    {
        return self::configured('contract_interpretation');
    }

    public static function historical(): self
    {
        return self::configured('contract_interpretation.historical');
    }

    private static function configured(string $prefix): self
    {
        return new self(
            (string) config($prefix.'.schema_version'),
            (string) config($prefix.'.prompt_version'),
            (string) config($prefix.'.validator_version'),
            (string) config($prefix.'.schema_path'),
            (string) config($prefix.'.prompt_path'),
            (string) config($prefix.'.parser_version', 'canonical-pricing-parser-v1'),
        );
    }

    public static function stored(?string $schema, ?string $prompt, ?string $validator): self
    {
        $versions = match ([$schema, $prompt, $validator]) {
            // Released config tuples only. The first two rows acquired validator-v1
            // through the original validator-version migration default.
            ['schema-v2', 'prompt-v5', 'validator-v1'] => [2, 5],
            ['schema-v3', 'prompt-v6', 'validator-v1'],
            ['schema-v3', 'prompt-v6', 'validator-v2'] => [3, 6],
            ['schema-v3', 'prompt-v7', 'validator-v3'] => [3, 7],
            ['schema-v3', 'prompt-v8', 'validator-v4'] => [3, 8],
            ['schema-v3', 'prompt-v9', 'validator-v5'] => [3, 9],
            ['schema-v3', 'prompt-v10', 'validator-v6'],
            ['schema-v3', 'prompt-v10', 'validator-v7'] => [3, 10],
            ['schema-v3', 'prompt-v11', 'validator-v8'] => [3, 11],
            ['schema-v3', 'prompt-v12', 'validator-v9'] => [3, 12],
            ['schema-v3', 'prompt-v13', 'validator-v10'] => [3, 13],
            ['schema-v3', 'prompt-v14', 'validator-v10'] => [3, 14],
            ['schema-v3', 'prompt-v15', 'validator-v11'] => [3, 15],
            ['schema-v3', 'prompt-v17', 'validator-v13'],
            ['schema-v3', 'prompt-v17', 'validator-v14'] => [3, 17],
            ['schema-v4', 'prompt-v19', 'validator-v16'],
            ['schema-v4', 'prompt-v19', 'validator-v17'] => [4, 19],
            ['schema-v5', 'prompt-v20', 'validator-v18'] => [5, 20],
            default => throw new InvalidArgumentException('Unsupported contract interpretation profile.'),
        };

        return new self($schema, $prompt, $validator,
            resource_path('contract-interpretation/schema-v'.$versions[0].'.json'),
            resource_path('contract-interpretation/system-prompt-v'.$versions[1].'.md'),
        );
    }
}
