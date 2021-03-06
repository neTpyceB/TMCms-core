<?php

namespace TMCms\Routing\Entity;

use TMCms\Orm\EntityRepository;

class PagesLandingEntityRepository extends EntityRepository
{
    protected $table_structure = [
        'fields' => [
            'page_id' => [
                'type' => 'int',
                'unsigned' => true,
            ],
            'href' => [
                'type' => 'varchar',
            ],
            'search_word' => [
                'type' => 'bool',
            ],
        ],
        'indexes' => [
            'page_id' => [
                'type' => 'key',
            ],
        ],
    ];

    public function setWhereAnyName($value)
    {
        $this->addWhereFieldAsString(' OR `name` = "' . $value . '" ');
    }
}