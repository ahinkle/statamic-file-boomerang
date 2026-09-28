<?php

namespace Ahinkle\FileBoomerang;

use Ahinkle\FileBoomerang\Exceptions\InvalidBatch;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User;

readonly class Editor
{
    public function __construct(public string $name, public string $email) {}

    public static function from(?Authenticatable $user): ?static
    {
        $email = static::clean(static::attribute($user, 'email'));

        if ($email === '') {
            return null;
        }

        return new static(static::clean(static::attribute($user, 'name')) ?: $email, $email);
    }

    /**
     * @return array{name: string, email: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): static
    {
        throw_unless(
            is_string($attributes['name'] ?? null) && is_string($attributes['email'] ?? null),
            InvalidBatch::because('the editor needs a name and an email'),
        );

        return new static(static::clean($attributes['name']), static::clean($attributes['email']));
    }

    protected static function attribute(?Authenticatable $user, string $key): mixed
    {
        return match (true) {
            $user instanceof User => $user->{$key}(),
            default => data_get($user, $key),
        };
    }

    protected static function clean(mixed $value): string
    {
        return is_string($value) ? Str::squish(str_replace(['<', '>'], '', $value)) : '';
    }
}
