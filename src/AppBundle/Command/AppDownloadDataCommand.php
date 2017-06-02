<?php

namespace AppBundle\Command;

use AppBundle\Document\Event;
use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Stopwatch\Stopwatch;

class AppDownloadDataCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('app:dl-rcdb')
            ->setDescription('---');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $i = 1;
        $fullStats = [];

        while ($i <= 10) {
            $output->writeln('Getting HTML from website...');
            $html = $this->getHTML($i);

            $output->writeln('Parsing HTML to get stats...');
            $stats = $this->getStats($html);

            $fullStats[] = $stats;

            $i++;
        }

        dump($fullStats);
        exit;
    }

    protected function getHTML($i)
    {
        $client = new Client(
            [
                'base_uri' => $this->getContainer()->getParameter('base_uri_2'),
                'timeout' => $this->getContainer()->getParameter('timeout'),
                'proxy' => $this->getContainer()->getParameter('proxy'),
                'debug' => false,
            ]
        );

        $response = $client->get(
            $i.'.htm',
            [
                'on_stats' => function (TransferStats $stats) {
                    echo $stats->getEffectiveUri() . "\n";
                    echo $stats->getTransferTime() . "\n";
                },
            ]
        );

        if ($response->getStatusCode() !== 200) {
            throw new \Exception('Status code is not 200');
        }

        return $response->getBody()->getContents();
    }

    protected function getStats($html)
    {
        $crawler = new Crawler($html);
        $crawler = $crawler->filter('section#objdiv h1');

        // Add name
        $coasterName = $crawler->getNode(0)->nodeValue;
        $values[] = $coasterName;

        $crawler = new Crawler($html);
        /** @var Crawler $crawler */
        $crawler = $crawler->filter('table#statTable td');

        /** @var \DOMElement $domElement */
        foreach ($crawler as $domElement) {
            $values[] = $domElement->nodeValue;
        }

        return $values;
    }

    protected function checkEvents(array $events)
    {
        $updates = [];
        $dm = $this->getContainer()->get('doctrine_mongodb')->getManager();

        foreach ($events as $id => $event) {
            $storedEvent = $this->getContainer()
                ->get('doctrine_mongodb')
                ->getRepository('AppBundle:Event')
                ->findOneBy(['eventId' => $event['id']]);

            if ($storedEvent instanceof Event) {
                if ($event['status'] !== $storedEvent->getStatus()) {
                    $storedEvent->setStatus($event['status']);
                    $dm->flush();

                    //$updates[] = $storedEvent;
                }
            } else {
                $newEvent = new Event();
                $newEvent->setEventId($event['id']);
                $newEvent->setInfo($event['info']);
                $newEvent->setStatus($event['status']);

                $dm->persist($newEvent);
                $dm->flush();

                $updates[] = $newEvent;
            }
        }

        return $updates;
    }

    protected function sendEmail($updates)
    {
        $message = \Swift_Message::newInstance()
            ->setSubject('Nouvel évènement')
            ->setFrom($this->getContainer()->getParameter('mail_from'))
            ->setTo($this->getContainer()->getParameter('mail_to'))
            ->setBody((string) implode("\n", $updates),'text/plain')
        ;

        $this->getContainer()->get('mailer')->send($message);
    }

    protected function sendNotification(array $events)
    {
        /** @var Event $event */
        foreach ($events as $event) {
            $message = (string) $event;
            $this->postToPushover('Nouvel évènement', $message);
        }
    }

    private function postToPushover($title, $message)
    {
        $client = new Client(
            [
                'base_uri' => $this->getContainer()->getParameter('pushover_uri'),
                'timeout' => 3,
                'proxy' => $this->getContainer()->getParameter('proxy'),
            ]
        );

        $response = $client->post(
            'messages.json',
            [
                'form_params' => [
                    'token' => $this->getContainer()->getParameter('pushover_token'),
                    'user' => $this->getContainer()->getParameter('pushover_user'),
                    'message' => $message,
                    'title' => $title,
                ],
            ]
        );
    }
}
