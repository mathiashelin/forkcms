<?php

namespace Backend\Modules\MediaLibrary\Manager\Base;

use Backend\Modules\MediaLibrary\Domain\MediaItem\Type;

class MediaItemTypeManager
{
    /** @var array */
    protected $values = [];

    /**
     * @param string $mediaItemType
     * @param array $values
     * @throws \Exception
     */
    public function add(string $mediaItemType, array $values)
    {
        try {
            $type = Type::fromString($mediaItemType);
        } catch (\Exception $e) {
            throw $e;
        }

        $this->values[$type->getType()] = $values;
    }

    /**
     * @param Type $mediaItemType
     * @return array
     */
    public function get(Type $mediaItemType): array
    {
        return $this->values[(string) $mediaItemType];
    }

    /**
     * @return array
     */
    public function getAll(): array
    {
        $values = [];
        foreach ($this->values as $key => $values) {
            $values = array_merge($values, $values);
        }
        return $values;
    }
}
