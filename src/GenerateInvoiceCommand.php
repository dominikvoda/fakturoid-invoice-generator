<?php declare(strict_types = 1);

namespace FakturoidInvoiceGenerator;

use DateTimeImmutable;
use Exception;
use Fakturoid\Client;
use Nette\Utils\FileSystem;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function in_array;

final class GenerateInvoiceCommand extends Command
{
    private const FCS = 'fcs';
    private const BE = 'be';
    private const SUBJECT_ARGUMENT = 'subject';
    private const PRICE_ARGUMENT = 'price';

    /**
     * @var mixed
     */
    private $config;


    public function __construct(array $config)
    {
        parent::__construct();
        $this->config = $config;
    }


    protected function configure(): void
    {
        $this->setName('fakturoid:generate:invoice');
        $this->setDescription('Generate invoice <subject: be|fcs> <price: float>');
        $this->addArgument(self::SUBJECT_ARGUMENT, InputArgument::REQUIRED, 'be|fcs');
        $this->addArgument(self::PRICE_ARGUMENT, InputArgument::REQUIRED, 'price');
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $subject = $this->getSubject($input);
        $subjectId = $this->getSubjectId($subject);

        $now = new DateTimeImmutable();

        $fakturoidClient = $this->createFakturoidClient();

        $issued = $now->modify('last day of this month');

        $lineName = 'Services according to agreement in month ' . $now->format('F Y');

        $existingInvoice = $this->getExistingInvoice($fakturoidClient, $subjectId, $lineName);

        $lineData = [
            'name' => $lineName,
            'vat_rate' => $this->shouldIncludeVat() ? 21 : 0,
            'unit_price' => (string)$this->getPrice($input),
        ];

        if ($existingInvoice !== null) {
            $lineData['id'] = $existingInvoice->lines[0]->id;
        }

        $invoiceData = [
            'subject_id' => $subjectId,
            'issued_on' => $issued->format('Y-m-d'),
            'due' => 15,
            'lines' => [$lineData],
        ];

        $response = $existingInvoice === null
            ? $fakturoidClient->createInvoice($invoiceData)
            : $fakturoidClient->updateInvoice($existingInvoice->id, $invoiceData);

        $output->writeln('Invoice saved in Fakturoid: ' . $response->getBody()->id);

        sleep(3);
        $invoiceId = $response->getBody()->id;
        $variableSymbol = $response->getBody()->variable_symbol;

        $response = $fakturoidClient->getInvoicePdf($invoiceId);

        $file = $this->getInvoiceFilePath($now, $variableSymbol, $subject);
        FileSystem::write($file, $response->getBody());

        $output->writeln('Invoice PDF generated: ' . $file);

        return 0;
    }


    private function createFakturoidClient(): Client
    {
        return new Client(
            $this->config['fakturoid']['slug'],
            $this->config['fakturoid']['email'],
            $this->config['fakturoid']['apiKey'],
            'Local PHP'
        );
    }


    private function getInvoiceFilePath(DateTimeImmutable $now, string $variableSymbol, string $subject): string
    {
        return __DIR__ . '/../invoices/' . $now->format('Y-m') . '/' . $subject . '_' . $variableSymbol . '.pdf';
    }


    private function getSubject(InputInterface $input): string
    {
        $subject = $input->getArgument(self::SUBJECT_ARGUMENT);

        if (in_array($subject, [self::FCS, self::BE], true)) {
            return $subject;
        }

        throw new Exception('Unknown subject, only "' . self::FCS . '" and "' . self::BE . '" is supported');
    }


    private function getSubjectId(string $subject): int
    {
        if ($subject === self::BE) {
            return $this->config['subjects']['beLtdBranch'];
        }

        return $this->config['subjects']['fcs'];
    }


    private function getPrice(InputInterface $input): float
    {
        $price = (float)$input->getArgument(self::PRICE_ARGUMENT);

        if($price <= 0){
            throw new Exception('Price has to be valid int|float and greater than 0');
        }

        if ($this->shouldIncludeVat()) {
            return $price / 121 * 100;
        }

        return $price;
    }


    private function shouldIncludeVat(): bool
    {
        return $this->config['invoicing']['includeVat'] === true;
    }


    private function getExistingInvoice(Client $fakturoidClient, int $subjectId, string $lineContent): ?stdClass
    {
        $invoices = $fakturoidClient->searchInvoices(['query' => $lineContent]);

        foreach ($invoices->getBody() as $invoice) {
            if ($invoice->subject_id === $subjectId) {
                return $invoice;
            }
        }

        return null;
    }
}
