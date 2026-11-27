<?php

declare(strict_types=1);

it('has the S3/R2 flysystem adapter installed', function (): void {
    expect(class_exists(League\Flysystem\AwsS3V3\AwsS3V3Adapter::class))->toBeTrue();
});
