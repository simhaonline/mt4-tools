<?php
namespace rosasurfer\rt\lib\synthetic\index;

use rosasurfer\exception\IllegalTypeException;
use rosasurfer\exception\UnimplementedFeatureException;

use rosasurfer\rt\lib\synthetic\AbstractSynthesizer;
use rosasurfer\rt\lib\synthetic\SynthesizerInterface as Synthesizer;

use function rosasurfer\rt\periodToStr;

use const rosasurfer\rt\PERIOD_M1;


/**
 * LFXJPY synthesizer
 *
 * A {@link Synthesizer} for calculating the LiteForex Japanese Yen index.
 *
 * <pre>
 * Formulas:
 * ---------
 * LFXJPY = USDJPY / USDLFX
 * LFXJPY = pow(AUDJPY * CADJPY * CHFJPY * EURJPY * GBPJPY * USDJPY, 1/7)
 * </pre>
 */
class LFXJPY extends AbstractSynthesizer {


    /** @var string[][] */
    protected $components = [
        'fast'    => ['USDJPY', 'USDLFX'],
        'crosses' => ['AUDJPY', 'CADJPY', 'CHFJPY', 'EURJPY', 'GBPJPY', 'USDJPY'],
    ];


    /**
     * {@inheritdoc}
     */
    public function getHistory($period, $time, $optimized = false) {
        if (!is_int($period))     throw new IllegalTypeException('Illegal type of parameter $period: '.gettype($period));
        if ($period != PERIOD_M1) throw new UnimplementedFeatureException(__METHOD__.'('.periodToStr($period).') not implemented');
        if (!is_int($time))       throw new IllegalTypeException('Illegal type of parameter $time: '.gettype($time));
        if ($optimized)           echoPre('[Warn]    '.str_pad($this->symbolName, 6).'::'.__FUNCTION__.'($optimized=TRUE)  skipping unimplemented feature');

        if (!$symbols = $this->loadComponents(first($this->components)))
            return [];
        if (!$time && !($time = $this->findCommonHistoryStartM1($symbols)))     // if no time was specified find the oldest available history
            return [];
        if (!$this->symbol->isTradingDay($time))                                // skip non-trading days
            return [];
        if (!$quotes = $this->loadComponentHistory($symbols, $time))
            return [];

        // calculate quotes
        echoPre('[Info]    '.str_pad($this->symbolName, 6).'  calculating M1 history for '.gmdate('D, d-M-Y', $time));
        $USDJPY = $quotes['USDJPY'];
        $USDLFX = $quotes['USDLFX'];

        $digits = $this->symbol->getDigits();
        $point  = $this->symbol->getPointValue();
        $bars   = [];

        // LFXJPY = USDJPY / USDLFX
        foreach ($USDJPY as $i => $bar) {
            $usdjpy = $USDJPY[$i]['open'];
            $usdlfx = $USDLFX[$i]['open'];
            $open   = round($usdjpy / $usdlfx, $digits);
            $iOpen  = (int) round($open/$point);

            $usdjpy = $USDJPY[$i]['close'];
            $usdlfx = $USDLFX[$i]['close'];
            $close  = round($usdjpy / $usdlfx, $digits);
            $iClose = (int) round($close/$point);

            $bars[$i]['time' ] = $bar['time'];
            $bars[$i]['open' ] = $open;
            $bars[$i]['high' ] = $iOpen > $iClose ? $open : $close;         // no min()/max() for performance
            $bars[$i]['low'  ] = $iOpen < $iClose ? $open : $close;
            $bars[$i]['close'] = $close;
            $bars[$i]['ticks'] = $iOpen==$iClose ? 1 : (abs($iOpen-$iClose) << 1);
        }
        return $bars;
    }
}
