<?php
namespace rosasurfer\rost\model;

use rosasurfer\db\NoSuchRecordException;
use rosasurfer\db\orm\DAO;
use rosasurfer\exception\IllegalTypeException;

use const rosasurfer\db\orm\meta\INT;
use const rosasurfer\db\orm\meta\STRING;


/**
 * DAO for accessing {@link DukascopySymbol} instances.
 */
class DukascopySymbolDAO extends DAO {


    /**
     * {@inheritdoc}
     */
    public function getMapping() {
        static $mapping; return $mapping ?: ($mapping=$this->parseMapping([
            'connection' => 'rosatrader',
            'table'      => 't_dukascopysymbol',
            'class'      => DukascopySymbol::class,
            'properties' => [
                ['name'=>'id',                                             'type'=>INT,    'primary'=>true],            // db:int
                ['name'=>'created',                                        'type'=>STRING,                ],            // db:text[datetime] GMT
                ['name'=>'modified',                                       'type'=>STRING, 'version'=>true],            // db:text[datetime] GMT

                ['name'=>'name',                                           'type'=>STRING,                ],            // db:text
                ['name'=>'digits',                                         'type'=>INT,                   ],            // db:int
                ['name'=>'tickHistoryFrom', 'column'=>'history_tick_from', 'type'=>STRING,                ],            // db:text[datetime] FXT
                ['name'=>'tickHistoryTo',   'column'=>'history_tick_to',   'type'=>STRING,                ],            // db:text[datetime] FXT
                ['name'=>'m1HistoryFrom',   'column'=>'history_M1_from',   'type'=>STRING,                ],            // db:text[datetime] FXT
                ['name'=>'m1HistoryTo',     'column'=>'history_M1_to',     'type'=>STRING,                ],            // db:text[datetime] FXT
            ],
            'relations' => [
                ['name'=>'rosaSymbol', 'assoc'=>'one-to-one', 'type'=>RosaSymbol::class, 'column'=>'rosasymbol_id'],    // db:int
            ],
        ]));
    }


    /**
     * Get the {@link DukascopySymbol} with the specified name.
     *
     * @param  string $name
     *
     * @return DukascopySymbol
     *
     * @throws NoSuchRecordException if no such instance was found
     */
    public function getByName($name) {
        if (!is_string($name)) throw new IllegalTypeException('Illegal type of parameter $name: '.getType($name));

        $name = $this->escapeLiteral($name);

        $sql = 'select *
                   from :DukascopySymbol
                   where name = '.$name;
        return $this->get($sql);
    }
}
