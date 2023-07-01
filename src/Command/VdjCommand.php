<?php

namespace App\Command;

use App\Enum\AutomixPoint;
use App\Enum\PoiType;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use VeeWee\Xml\Reader\Reader;
use VeeWee\Xml\Reader\Matcher;
use VeeWee\Xml\Writer\Writer;
use function Symfony\Component\String\u;

#[AsCommand(
    name: 'vdj',
    description: 'Add a short description for your command',
)]
class VdjCommand extends Command
{
    private SymfonyStyle $io;
    private string $dataDir;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        string $name = null
    ) {
        $this->dataDir = $projectDir . '/data';
        parent::__construct($name);
    }

    public function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = "/shared/httpd/vdj/original-database.xml";
        $destination = "/shared/httpd/vdj/original-database.xml";

        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->load($source);
        //$dom->load("{$this->dataDir}/database.xml");

        $songs = $dom->getElementsByTagName('Song');

        $progress = $this->io->createProgressBar();
        $progress->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        /**
         * @var \DOMElement $song
         */
        foreach ($progress->iterate($songs) as $song) {
            /*$filePath = $song->getAttribute('FilePath');
            if (!str_contains($filePath, 'AMBIANCE & SOLEILS')) {
                continue;
            }*/
            if ($this->isNeedProcessing($song)) {
                //$this->renameSongIfMultipleSpace($song);
                $progress->setMessage($song->getAttribute('FilePath'));
                //$this->io->info("Processing {$song->getAttribute('FilePath')}");
                $this->clearPois($song);
                $this->addAutomixPoints($song);
                $this->setTrackProcessed($song);
                $this->manageNoScan($song);
                $song->setAttribute('AutomixProcessed', '1');
                //$this->io->success("Processed {$song->getAttribute('FilePath')}");
            } else {
                $this->manageNoScan($song);
                //$this->io->caution("Skipping {$song->getAttribute('FilePath')} as it's already processed");
            }
        }

        $content = $dom->saveXML();

        $content = tidy_repair_string($content, [
            'indent' => true,
            'indent-spaces' => 1,
            'input-xml' => true,
            'output-xml' => true,
            'wrap' => 0,
        ]);
        $content = str_replace(
            ['"/>', 'encoding="utf-8"?>'],
            ['" />', 'encoding="UTF-8"?>'],
            $content
        );
        $content = preg_replace('~(*BSR_ANYCRLF)\R~', "\r\n", $content);

        file_put_contents($destination, $content);

        return Command::SUCCESS;
    }

    private function clearPois(\DOMElement $song): void
    {
        $pois = $this->getPoisFromSong($song);
        foreach ($pois as $poi) {
            $type = PoiType::from($poi->getAttribute('Type'));
            $num = (int)$poi->getAttribute('Num');
            $name = $poi->getAttribute('Name');
            if (
                (
                    in_array($type, [PoiType::AUTOMIX, PoiType::REMIX, PoiType::ACTION], true) ||
                    $type === PoiType::CUE && !in_array($num, [2, 7], true)
                ) && $name !== 'TO_MUTE_VOICE'
            ) {
                $poi->remove();
            }
        }
    }

    private function isNeedProcessing(\DOMElement $song): bool
    {
        $pois = $this->getPoisFromSong($song);
        $isAlreadyProcessed = $this->isTrackProcessed($song);

        $containsCues = false;
        foreach ($pois as $poi) {
            $type = PoiType::from($poi->getAttribute('Type'));
            $num = (int)$poi->getAttribute('Num');
            $name = $poi->getAttribute('Name');
            if (
                $type === PoiType::CUE &&
                in_array($num, [2, 7], true) &&
                in_array($name, ['TO_MIX_HT_START', 'TO_MIX_HT_END'], true)
            ) {
                $containsCues = true;
            }
        }

        $isNeedProcessing = $containsCues && !$isAlreadyProcessed;

        if ($containsCues === false && false === $isAlreadyProcessed) {
            $tags = $song->getElementsByTagName('Tags')->item(0);
            $user1Value = $tags->getAttribute('User1');
            if (u($user1Value)->containsAny('#XTDaFAIRE') === false) {
                $tags->setAttribute('User1', '#XTDaFAIRE ' . $user1Value);
            }
        }

        if ($isNeedProcessing) {
            $this->setTrackProcessed($song);
        }
        return $containsCues || !$isAlreadyProcessed;
    }

    /**
     * @param \DOMElement $song
     * @return ArrayCollection<\DOMElement>
     */
    private function getPoisFromSong(\DOMElement $song): ArrayCollection
    {
        $pois = [];
        foreach ($song->getElementsByTagName('Poi') as $poi) {
            $pois[] = $poi;
        }

        return new ArrayCollection($pois);
    }

    public function getAttributes(\DOMElement $node): ArrayCollection
    {
        $attributes = [];
        foreach ($node->attributes as $attribute) {
            $attributes[$attribute->name] = $attribute->value;
        }
        return new ArrayCollection($attributes);
    }

    private function manageNoScan(\DOMElement $song): void
    {
        $tags = $song->getElementsByTagName('Tags')->item(0);
        $scan = $song->getElementsByTagName('Scan')->item(0);
        $user1Value = $tags->getAttribute('User1');
        if (u($user1Value)->containsAny('#NoScan') && $scan !== null) {
            $this->clearNoScanFromUser1($song);
        }
    }

    private function clearNoScanFromUser1(\DOMElement $song): void
    {
        $tags = $song->getElementsByTagName('Tags')->item(0);
        $user1Value = $tags->getAttribute('User1');
        if (u($user1Value)->containsAny('#NoScan')) {
            $tags->setAttribute('User1', u($user1Value)->replace('#NoScan', '')->trim());
        }
    }

    private function addNoScanToUser1(\DOMElement $song): void
    {
        $tags = $song->getElementsByTagName('Tags')->item(0);
        $user1Value = $tags->getAttribute('User1');

        if (u($user1Value)->containsAny('#NoScan') === false) {
            $tags->setAttribute('User1', '#NoScan ' . $user1Value);
        }
    }

    private function addAutomixPoints(\DOMElement $song): void
    {
        $pois = $this->getPoisFromSong($song);
        $scan = $song->getElementsByTagName('Scan')->item(0);
        if (null === $scan) {
            $this->addNoScanToUser1($song);
            return;
        } else {
            $this->clearNoScanFromUser1($song);
        }
        $secondBetweenBeats = (float)$scan->attributes->getNamedItem('Bpm')->nodeValue;
        $realBpm = round(1 / $secondBetweenBeats * 60);
        $automixLength = 12;
        $secondBetweenBeats = 60 / $realBpm;

        $secondMultiplier = 1 + ($realBpm / 10 / 25);

        $second = 6;//(($secondBetweenBeats * $secondMultiplier) * 6) * $secondMultiplier;
        if ($realBpm >= 100) {
            $second = 7;
        }
        /*if ($realBpm >= 100 && $realBpm <= 125) {
            $second = 8;
        } elseif ($realBpm <= 200) {
            $second = 10;
        }*/
        foreach ($pois as $poi) {
            $type = PoiType::from($poi->getAttribute('Type'));
            $num = (int)$poi->getAttribute('Num');
            $name = $poi->getAttribute('Name');

            if ($type === PoiType::CUE && $num === 2) {
                //$this->addAutomixStartPoi($song, $poi, '20', $automixLength - $second);
                $this->addAutomixPoint($song, $poi, $second * -1, AutomixPoint::FADE_START);
                //$this->addAutomixStopPoi($song, $poi, '21', $second);
                $poi->setAttribute('Name', 'MIX_HT_START');
            }
            if ($type === PoiType::CUE && $num === 7) {
                $this->addAutomixStartPoi($song, $poi, '22', $automixLength - $second);
                $this->addAutomixPoint($song, $poi, $second, AutomixPoint::FADE_END);
                $this->addAutomixStopPoi($song, $poi, '23', $second);
                $this->addOppositeDeckEQLowTo0Percent($song, $poi, $second);
                $this->addPoiTransitionEQLowFrom0To50Percent($song, $poi, $second);
                $this->addPoiTransitionEQLowFrom50To0Percent($song, $poi, $second);
                $this->addReverbEffect($song, $poi, $second);
                $poi->setAttribute('Name', 'MIX_HT_END');
            }

            if ($type === PoiType::CUE && $num === 5 && $name === 'TO_MUTE_VOICE') {
                $this->transformMuteVoiceToAction($song, $poi);
            }
        }
    }

    private function addAutomixStartPoi(\DOMElement $song, \DOMElement $poi, int $num, float $seconds): void
    {
        $currentPosition = (float)$poi->getAttribute('Pos');

        $poi = clone $poi;
        $poi->setAttribute('Type', PoiType::CUE->value);
        $poi->setAttribute('Name', 'Automix Start');
        $poi->setAttribute('Num', $num);
        $poi->setAttribute('Pos', $currentPosition - $seconds);

        $song->appendChild($poi);
    }

    private function addAutomixStopPoi(\DOMElement $song, \DOMElement $poi, int $num, float $seconds): void
    {
        $currentPosition = (float)$poi->getAttribute('Pos');

        $poi = clone $poi;
        $poi->setAttribute('Type', PoiType::CUE->value);
        $poi->setAttribute('Name', 'Automix Stop');
        $poi->setAttribute('Num', $num);
        $poi->setAttribute('Pos', $currentPosition + $seconds);

        $song->appendChild($poi);
    }

    private function addAutomixPoint(\DOMElement $song, \DOMElement $poi, float $seconds, AutomixPoint $point): void
    {
        $automixPoi = clone $poi;
        $automixPoi->setAttribute('Type', PoiType::AUTOMIX->value);
        $automixPoi->setAttribute('Name', $point->name);
        $automixPoi->setAttribute('Point', $point->value);
        $automixPoi->removeAttribute('Num');

        $currentPosition = (float)$poi->getAttribute('Pos');
        $automixPoi->setAttribute('Pos', $currentPosition + $seconds);

        $song->appendChild($automixPoi);

        //$this->io->success("Added automix point at {$currentPosition} seconds");
    }

    private function addOppositeDeckEQLowTo0Percent(\DOMElement $song, \DOMElement $poi, float $seconds): void
    {
        $currentPosition = (float)$poi->getAttribute('Pos');

        $poi = clone $poi;
        $poi->removeAttribute('Num');
        $poi->setAttribute('Type', PoiType::ACTION->value);
        $poi->setAttribute('Name', 'Opposite Deck EQ Low to 0%');
        $poi->setAttribute('Pos', $currentPosition - $seconds + 0.5);
        $poi->setAttribute('Action',
            'deck 1 param_equal get_activedeck 1 ? deck 2 eq_low_freq 0% : deck 1 eq_low_freq 0%');

        $song->appendChild($poi);
    }

    private function addPoiTransitionEQLowFrom50To0Percent(\DOMElement $song, \DOMElement $poi, float $seconds): void
    {
        $currentPosition = (float)$poi->getAttribute('Pos');

        $poi = clone $poi;
        $poi->removeAttribute('Num');
        $poi->setAttribute('Type', PoiType::ACTION->value);
        $poi->setAttribute('Name', 'Poi Transition EQ Low from 50% to 0%');
        $poi->setAttribute('Pos', $currentPosition - $seconds + ($seconds / 1.5));
        $poi->setAttribute('Action',
            'repeat_stop \'eqlatb\' && repeat_start_instant \'eqlatb\' 96ms 60 && eq_low_freq && param_bigger 0.00 ? eq_low_freq -1% : repeat_stop \'eqlatb\'');

        $song->appendChild($poi);
    }

    private function addPoiTransitionEQLowFrom0To50Percent(\DOMElement $song, \DOMElement $poi, float $seconds): void
    {
        $currentPosition = (float)$poi->getAttribute('Pos');

        $poi = clone $poi;
        $poi->removeAttribute('Num');
        $poi->setAttribute('Type', PoiType::ACTION->value);
        $poi->setAttribute('Name', 'Poi Transition EQ Low from 0% to 50%');
        $poi->setAttribute('Pos', $currentPosition - $seconds + ($seconds / 2));
        $poi->setAttribute('Action',
            'repeat_stop \'eqlata\' && repeat_start_instant \'eqlata\' 96ms 60 && action_deck 1 ? deck 2 eq_low_freq && param_smaller 0.50 ? deck 2 eq_low_freq +1% : repeat_stop \'eqlata\' : deck 1 eq_low_freq && param_smaller 0.50 ? deck 1 eq_low_freq +1% : repeat_stop \'eqlata\'');

        $song->appendChild($poi);
    }

    private function addReverbEffect(\DOMElement $song, \DOMElement $poi, float $seconds): void
    {
        $currentPosition = (float)$poi->getAttribute('Pos');

        $poi = clone $poi;
        $poi->removeAttribute('Num');
        $poi->setAttribute('Type', PoiType::ACTION->value);
        $poi->setAttribute('Name', 'Reverb Effect');
        $poi->setAttribute('Pos', $currentPosition - $seconds);
        $poi->setAttribute('Action',
            'effect_active 1 \'echo\' && effect_slider 1 0% && wait 4000ms && repeat_stop \'reverb_at_eff\' && repeat_start_instant \'reverb_at_eff\' 5ms 50 && effect_slider 1 +1% && wait 8000ms && effect_active 1 \'echo\' && effect_slider 1 0%');

        $song->appendChild($poi);
    }

    private function transformMuteVoiceToAction(\DOMElement $song, \DOMElement $poi): void
    {
        $poi->setAttribute('Type', PoiType::ACTION->value);
        $poi->setAttribute('Action', 'mute_stem \'Vocal\'');
        $poi->setAttribute('Name', 'MUTE_VOICE');
    }

    private function setTrackProcessed(\DOMElement $song, bool $processed = true): void
    {
        $tags = $song->getElementsByTagName('Tags')->item(0);
        $tags->setAttribute('User2', $processed ? '1' : '0');
        $user1Value = $tags->getAttribute('User1');
        if ($user1Value === 'XTDaFAIRE') {
            $tags->removeAttribute('User1');
        }
    }

    private function isTrackProcessed(\DOMElement $song): bool
    {
        $tags = $song->getElementsByTagName('Tags')->item(0);
        return $tags->getAttribute('User2') === '1';
    }

    private function renameSongIfMultipleSpace(\DOMElement $song): void
    {
        $originalFilePath = $song->getAttribute('FilePath');
        $originalFormattedFilePath = u($originalFilePath)->replaceMatches('/\s+/', ' ')->toString();

        $filePath = u($originalFilePath)->replace('C:\\Music\\', '/shared/httpd/vdj/musics/')->replace('\\',
            '/')->toString();
        $formattedFilePath = u($originalFormattedFilePath)->replace('C:\\Music\\', '/shared/httpd/vdj/musics/')
            ->replace('\\', '/')->toString();


        $dirname = pathinfo($filePath, PATHINFO_DIRNAME);
        $dirnameFormatted = pathinfo($formattedFilePath, PATHINFO_DIRNAME);
        if ($dirname !== $dirnameFormatted) {
            if (!file_exists($dirnameFormatted)) {
                mkdir($dirnameFormatted, 0777, true);
            }
        }
        if ($filePath !== $formattedFilePath) {
            if (file_exists($filePath)) {
                rename($filePath, $formattedFilePath);
            }
            $vdjstemsFilePath = str_replace('.mp3', '.vdjstems', $filePath);
            $vdjstemsFormattedFilePath = str_replace('.mp3', '.vdjstems', $formattedFilePath);
            if (file_exists($vdjstemsFilePath)) {
                rename($vdjstemsFilePath, $vdjstemsFormattedFilePath);
            }
        }

        // Check if dir is empty
        if (is_dir($dirname) && count(scandir($dirname)) === 2) {
            rmdir($dirname);
        }
    }
}
