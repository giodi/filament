<?php

namespace Filament\Schema\Concerns;

use Closure;
use Exception;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Schema\ComponentContainer;
use Filament\Schema\Components\Attributes\Exposed;
use Filament\Schema\Components\Component;
use Filament\Support\Concerns\ResolvesDynamicLivewireProperties;
use Filament\Support\Contracts\TranslatableContentDriver;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use ReflectionMethod;
use ReflectionNamedType;

trait InteractsWithSchemas
{
    use ResolvesDynamicLivewireProperties;
    use WithFileUploads;

    /**
     * @var array <string, TemporaryUploadedFile | null>
     */
    public array $componentFileAttachments = [];

    /**
     * @var array<string, mixed>
     */
    protected array $oldSchemaState = [];

    /**
     * @var array<string>
     */
    #[Locked]
    public array $discoveredSchemaNames = [];

    /**
     * @var array<string, ?ComponentContainer>
     */
    protected array $cachedSchemas = [];

    protected bool $isCachingSchemas = false;

    public function isCachingSchemas(): bool
    {
        return $this->isCachingSchemas;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function callSchemaComponentMethod(string $componentKey, string $method, array $arguments = []): mixed
    {
        $component = $this->getSchemaComponent($componentKey);

        if (! $component) {
            return null;
        }

        if (! method_exists($component, $method)) {
            return null;
        }

        $methodReflection = new ReflectionMethod($component, $method);

        if (! $methodReflection->getAttributes(Exposed::class)) {
            return null;
        }

        if ($methodReflection->getAttributes(Renderless::class)) {
            $this->skipRender();
        }

        return $component->{$method}(...$arguments);
    }

    public function getSchemaComponentFileAttachment(string $componentKey): ?TemporaryUploadedFile
    {
        return data_get($this->componentFileAttachments, $componentKey);
    }

    /**
     * @return class-string<TranslatableContentDriver> | null
     */
    public function getFilamentTranslatableContentDriver(): ?string
    {
        return null;
    }

    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        $driver = $this->getFilamentTranslatableContentDriver();

        if (! $driver) {
            return null;
        }

        return app($driver, ['activeLocale' => $this->getActiveSchemaLocale() ?? app()->getLocale()]);
    }

    public function getActiveSchemaLocale(): ?string
    {
        return null;
    }

    public function getOldSchemaState(string $statePath): mixed
    {
        return data_get($this->oldSchemaState, $statePath);
    }

    public function updatingInteractsWithSchemas(string $statePath): void
    {
        $statePath = (string) str($statePath)->before('.');

        $this->oldSchemaState[$statePath] = data_get($this, $statePath);
    }

    public function updatedInteractsWithSchemas(string $statePath): void
    {
        foreach ($this->getCachedSchemas() as $form) {
            $form->callAfterStateUpdated($statePath);
        }
    }

    public function getSchemaComponent(string $key): ?Component
    {
        if (! str($key)->contains('.')) {
            return null;
        }

        $schemaName = (string) str($key)->before('.');

        $schema = $this->getSchema($schemaName);

        if (! $schema) {
            throw new Exception("Schema [{$schemaName}] not found.");
        }

        return $schema->getComponent($key, isAbsoluteKey: true);
    }

    protected function cacheSchema(string $name, ComponentContainer | Closure | null $schema = null): ?ComponentContainer
    {
        $this->isCachingSchemas = true;

        $schema = value($schema);

        try {
            if ($schema) {
                return $this->cachedSchemas[$name] = $schema->key($name);
            }

            // If null was explicitly passed as the schema, unset the cached schema.
            if (func_num_args() === 2) {
                unset($this->cachedSchemas[$name]);

                return null;
            }

            if (method_exists($this, $name)) {
                $methodName = $name;
            } elseif (method_exists($this, "{$name}Schema")) {
                $methodName = "{$name}Schema";
            } elseif (
                str($name)->startsWith('mountedAction') &&
                method_exists($this, 'cacheMountedActions') &&
                property_exists($this, 'mountedActions')
            ) {
                $this->cacheMountedActions($this->mountedActions);

                return $this->getSchema($name);
            } else {
                unset($this->cachedSchemas[$name]);

                return null;
            }

            $methodReflection = new ReflectionMethod($this, $name);
            $parameterReflection = $methodReflection->getParameters()[0] ?? null;

            if (! $parameterReflection) {
                $returnTypeReflection = $methodReflection->getReturnType();

                if (! $returnTypeReflection) {
                    unset($this->cachedSchemas[$name]);

                    return null;
                }

                if (! $returnTypeReflection instanceof ReflectionNamedType) {
                    unset($this->cachedSchemas[$name]);

                    return null;
                }

                $type = $returnTypeReflection->getName();

                if (! is_a($type, ComponentContainer::class, allow_string: true)) {
                    unset($this->cachedSchemas[$name]);

                    return null;
                }

                return $this->cachedSchemas[$name] = ($this->{$methodName}())->key($name);
            }

            $typeReflection = $parameterReflection->getType();

            if (! $typeReflection) {
                unset($this->cachedSchemas[$name]);

                return null;
            }

            if (! $typeReflection instanceof ReflectionNamedType) {
                unset($this->cachedSchemas[$name]);

                return null;
            }

            $type = $typeReflection->getName();

            if (! class_exists($type)) {
                unset($this->cachedSchemas[$name]);

                return null;
            }

            if (! is_a($type, ComponentContainer::class, allow_string: true)) {
                unset($this->cachedSchemas[$name]);

                return null;
            }

            $schema = $this->makeSchema($type, $name);

            return $this->cachedSchemas[$name] = $this->{$name}($schema)->key($name);
        } finally {
            $this->isCachingSchemas = false;
        }
    }

    /**
     * @param  class-string<ComponentContainer>  $type
     */
    protected function makeSchema(string $type, ?string $name): ComponentContainer
    {
        if (method_exists($this, 'makeForm') && is_a($type, Form::class, allow_string: true)) {
            return $this->makeForm();
        }

        if (method_exists($this, 'makeInfolist') && is_a($type, Infolist::class, allow_string: true)) {
            return $this->makeInfolist();
        }

        return $type::make($this);
    }

    protected function hasCachedSchema(string $name): bool
    {
        return array_key_exists($name, $this->getCachedSchemas());
    }

    public function getSchema(string $name): ?ComponentContainer
    {
        if ($this->hasCachedSchema($name)) {
            return $this->getCachedSchemas()[$name];
        }

        return $this->cacheSchema($name);
    }

    /**
     * @return array<string, ?ComponentContainer>
     */
    public function getCachedSchemas(): array
    {
        foreach ($this->discoveredSchemaNames as $schemaName) {
            if (array_key_exists($schemaName, $this->cachedSchemas)) {
                continue;
            }

            $this->cacheSchema($schemaName);
        }

        $this->discoveredSchemaNames = [];

        return $this->cachedSchemas;
    }

    /**
     * @param  array<string, array<mixed>> | null  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     */
    public function validate($rules = null, $messages = [], $attributes = []): array
    {
        try {
            return parent::validate($rules, $messages, $attributes);
        } catch (ValidationException $exception) {
            $this->onValidationError($exception);

            $this->dispatch('form-validation-error', livewireId: $this->getId());

            throw $exception;
        }
    }

    /**
     * @param  string  $field
     * @param  array<string, array<mixed>>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @param  array<string, string>  $dataOverrides
     * @return array<string, mixed>
     */
    public function validateOnly($field, $rules = null, $messages = [], $attributes = [], $dataOverrides = [])
    {
        try {
            return parent::validateOnly($field, $rules, $messages, $attributes, $dataOverrides);
        } catch (ValidationException $exception) {
            $this->onValidationError($exception);

            $this->dispatch('form-validation-error', livewireId: $this->getId());

            throw $exception;
        }
    }

    protected function onValidationError(ValidationException $exception): void
    {
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function prepareForValidation($attributes): array
    {
        foreach ($this->getCachedSchemas() as $form) {
            $attributes = $form->mutateStateForValidation($attributes);
        }

        return $attributes;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function getRules(): array
    {
        $rules = parent::getRules();

        foreach ($this->getCachedSchemas() as $form) {
            $rules = [
                ...$rules,
                ...$form->getValidationRules(),
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function getValidationAttributes(): array
    {
        $attributes = parent::getValidationAttributes();

        foreach ($this->getCachedSchemas() as $form) {
            $attributes = [
                ...$attributes,
                ...$form->getValidationAttributes(),
            ];
        }

        return $attributes;
    }
}