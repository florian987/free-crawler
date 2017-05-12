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

class AppCheckUpdatesCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('app:check-updates')
            ->setDescription('Check new events on website and send notification')
            ->addOption('notification', null, InputOption::VALUE_NONE, 'Send notification instead of mail');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start('command');

        $output->writeln('Getting HTML from website...');
        $html = $this->getHTML();

        $output->writeln('Parsing HTML to get events...');
        $events = $this->getEvents($html);

        $output->writeln('Checking updates or new events...');
        $updates = $this->checkEvents($events);

        if(count($updates) > 0) {
            $output->writeln(count($updates) . ' new or updated events.');

            if($input->getOption('notification')) {
                $output->writeln('Sending notification...');
                $this->sendNotification($updates);
            } else {
                $output->writeln('Sending mail...');
                $this->sendEmail($updates);
            }

        }

        $output->writeln('Done.');
        $end = $stopwatch->stop('command');
        $output->writeln('Time: ' . $end->getEndTime() . 'ms');
    }

    protected function getHTML()
    {
        $client = new Client(
            [
                'base_uri' => $this->getContainer()->getParameter('base_uri'),
                'timeout' => $this->getContainer()->getParameter('timeout'),
                'proxy' => $this->getContainer()->getParameter('proxy'),
                'debug' => false,
            ]
        );

        $response = $client->get(
            '/',
            [
                'headers' => [
                    'Cookie' => $this->getContainer()->getParameter('cookie_value'),
                ],
                'query' => $this->getContainer()->getParameter('query_string'),
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

    protected function getEvents($html)
    {
        $crawler = new Crawler($html);
        $events = [];

        $crawler = $crawler->filter('section#block-system-main div.views-row > div');

        foreach ($crawler as $domElement) {
            $id = $domElement->getAttribute("id");
            $info = [];

            $infoCrawler = $crawler->filter("div[id='$id'] div.field-item");
            if ($infoCrawler->count() > 1) {
                foreach ($infoCrawler as $item) {
                    if (strlen($item->nodeValue) > 1) {
                        $info[] = $item->nodeValue;
                    }
                }
            }

            $status = '';
            $statusCrawler = $crawler->filter("div[id='$id'] div.group-footer div.button-empty");

            if ($statusCrawler->count() == 1) {
                $status = $statusCrawler->text();
            } else {
                $statusCrawler = $crawler->filter("div[id='$id'] div.group-footer div.content-right");
                if ($statusCrawler->count() == 1) {
                    $status = $statusCrawler->text();
                }
            }

            $events[$id] = ['id' => $id, 'status' => $status, 'info' => $info];
        }

        return $events;
    }

    protected function checkEvents(array $events)
    {
        $updates = [];

        foreach ($events as $id => $event) {
            $storedEvent = $this->getContainer()
                ->get('doctrine_mongodb')
                ->getRepository('AppBundle:Event')
                ->findOneBy(['eventId' => $event['id']]);

            $dm = $this->getContainer()->get('doctrine_mongodb')->getManager();

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

    protected function sendNotification(array $updates)
    {
        $client = new Client(
            [
                'base_uri' => $this->getContainer()->getParameter('pushover_uri'),
                'timeout' => 3,
                'proxy' => $this->getContainer()->getParameter('proxy'),
            ]
        );

        /** @var Event $event */
        foreach ($updates as $event) {
            $response = $client->post(
                'messages.json',
                [
                    'form_params' => [
                        'token' => $this->getContainer()->getParameter('pushover_token'),
                        'user' => $this->getContainer()->getParameter('pushover_user'),
                        'message' => (string) $event,
                        'title' => 'Nouvel évènement',
                    ],
                ]
            );
        }
    }
}
