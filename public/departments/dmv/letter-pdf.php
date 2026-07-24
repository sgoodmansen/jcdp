<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

class SimplePdf
{
    private array $objects = [];
    private array $content = [];
    private float $x = 54;
    private float $y = 742;
    private float $lineHeight = 14;

    public function setCursor(float $x, float $y): void
    {
        $this->x = $x;
        $this->y = $y;
    }

    public function text(string $text, float $x, float $y, int $size = 11, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $escaped = $this->escapeText($text);
        $this->content[] = "BT /{$font} {$size} Tf {$x} {$y} Td ({$escaped}) Tj ET";
    }

    public function textRight(string $text, float $rightX, float $y, int $size = 11, bool $bold = false): void
    {
        $width = strlen($text) * $size * 0.5;
        $this->text($text, $rightX - $width, $y, $size, $bold);
    }

    public function line(string $text = '', int $size = 11, bool $bold = false): void
    {
        if ($text !== '') {
            $this->text($text, $this->x, $this->y, $size, $bold);
        }
        $this->y -= $this->lineHeight;
    }

    public function blank(float $height = 10): void
    {
        $this->y -= $height;
    }

    public function wrapped(string $text, float $width = 500, int $size = 11): void
    {
        $maxChars = max(35, (int) floor($width / ($size * 0.5)));
        $lines = explode("\n", wordwrap($text, $maxChars));

        foreach ($lines as $line) {
            $this->line($line, $size);
        }
    }

    public function output(string $filename): void
    {
        $content = implode("\n", $this->content);
        $this->objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($this->objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $objectNumber = $index + 1;
            $pdf .= "{$objectNumber} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($this->objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($this->objects); $i++) {
            $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size " . (count($this->objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    private function escapeText(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}

$id = (int) ($_GET['id'] ?? 0);
$statement = db()->prepare(
    'SELECT
        dmv_title_requests.id,
        dmv_title_requests.request_date,
        dmv_title_requests.registrant_name,
        dmv_title_requests.registrant_name_2,
        dmv_title_requests.registrant_address,
        dmv_title_requests.registrant_city,
        dmv_title_requests.registrant_state,
        dmv_title_requests.registrant_zip_code,
        dmv_title_requests.registrant_phone,
        dmv_title_requests.vehicle_year,
        COALESCE(dmv_vehicle_makes.name, dmv_title_requests.vehicle_make) AS vehicle_make,
        COALESCE(dmv_vehicle_models.name, dmv_title_requests.vehicle_model) AS vehicle_model,
        dmv_title_requests.vin,
        dmv_lienholders.company_name,
        dmv_lienholders.contact_name,
        dmv_lienholders.mailing_address,
        dmv_lienholders.city AS lienholder_city,
        dmv_lienholders.state AS lienholder_state,
        dmv_lienholders.zip_code AS lienholder_zip_code,
        dmv_lienholders.phone AS lienholder_phone,
        dmv_lienholders.phone_extension AS lienholder_phone_extension,
        dmv_lienholders.fax AS lienholder_fax,
        users.first_name AS clerk_first_name,
        users.last_name AS clerk_last_name,
        users.email AS clerk_email
     FROM dmv_title_requests
     INNER JOIN dmv_lienholders ON dmv_lienholders.id = dmv_title_requests.lienholder_id
     LEFT JOIN users ON users.id = dmv_title_requests.created_by
     LEFT JOIN dmv_vehicle_makes ON dmv_vehicle_makes.id = dmv_title_requests.vehicle_make_id
     LEFT JOIN dmv_vehicle_models ON dmv_vehicle_models.id = dmv_title_requests.vehicle_model_id
     WHERE dmv_title_requests.id = :id'
);
$statement->execute(['id' => $id]);
$request = $statement->fetch();

if (!$request) {
    http_response_code(404);
    echo 'Letter not found';
    exit;
}

$clerkName = trim(($request['clerk_first_name'] ?? '') . ' ' . ($request['clerk_last_name'] ?? ''));
$clerkEmail = $request['clerk_email'] ?? '';

if ($clerkName === '') {
    $clerkName = 'Jefferson County DMV';
}

$registrantNames = $request['registrant_name'];
if (!empty($request['registrant_name_2'])) {
    $registrantNames .= ' and ' . $request['registrant_name_2'];
}

$pdf = new SimplePdf();
$pdf->text('Jefferson County Assessor', 54, 742, 16, true);
$pdf->text('PO Box 538 Rigby, ID 83442', 54, 724);
$pdf->text('Phone: (208) 745-9228', 54, 710);
$pdf->text('Fax: (208) 745-5240', 54, 696);
$pdf->text('Assessor: Jessica Roach', 54, 682);

$pdf->text(date('n/j/Y', strtotime($request['request_date'])), 420, 682);

$pdf->setCursor(54, 642);
$pdf->line($request['company_name'], 11, true);
if (!empty($request['contact_name'])) {
    $pdf->line('ATTN: ' . $request['contact_name']);
}
$pdf->line($request['mailing_address']);
$pdf->line($request['lienholder_city'] . ' ' . $request['lienholder_state'] . ' ' . $request['lienholder_zip_code']);
if (!empty($request['lienholder_fax'])) {
    $pdf->line('Fax ' . $request['lienholder_fax']);
}
if (!empty($request['lienholder_phone'])) {
    $phoneLine = 'Phone ' . $request['lienholder_phone'];
    if (!empty($request['lienholder_phone_extension'])) {
        $phoneLine .= ' ext. ' . $request['lienholder_phone_extension'];
    }
    $pdf->line($phoneLine);
}

$pdf->blank(16);
$pdf->wrapped('The following person has made an application to register the following vehicle in Idaho. It is necessary that the present title be transferred to an Idaho title which will be returned to you as lienholder.');
$pdf->blank(8);
$pdf->wrapped('Please, mail the ORIGINAL PAPER TITLE and a copy of this letter to the address shown above. The LIEN will be recorded on the Idaho title and mailed to you.');
$pdf->blank(8);
$pdf->wrapped('The Idaho title showing your lien will be forwarded to you immediately upon issuance by the Idaho Transportation Department, Boise, Idaho.');

$pdf->blank(18);
$detailsY = 400;
$leftLabelX = 108;
$leftValueX = 120;
$rightLabelX = 378;
$rightValueX = 390;

$pdf->textRight('Owner', $leftLabelX, $detailsY, 11, true);
$pdf->text($registrantNames, $leftValueX, $detailsY);
$pdf->textRight('Year', $rightLabelX, $detailsY, 11, true);
$pdf->text((string) $request['vehicle_year'], $rightValueX, $detailsY);

$pdf->textRight('Address', $leftLabelX, $detailsY - 18, 11, true);
$pdf->text($request['registrant_address'], $leftValueX, $detailsY - 18);
$pdf->textRight('Make', $rightLabelX, $detailsY - 18, 11, true);
$pdf->text((string) $request['vehicle_make'], $rightValueX, $detailsY - 18);

$pdf->text($request['registrant_city'] . ' ' . $request['registrant_state'] . ' ' . $request['registrant_zip_code'], $leftValueX, $detailsY - 36);
$pdf->textRight('Model', $rightLabelX, $detailsY - 36, 11, true);
$pdf->text((string) $request['vehicle_model'], $rightValueX, $detailsY - 36);

if (!empty($request['registrant_phone'])) {
    $pdf->textRight('Phone', $leftLabelX, $detailsY - 54, 11, true);
    $pdf->text($request['registrant_phone'], $leftValueX, $detailsY - 54);
}
$pdf->textRight('VIN', $rightLabelX, $detailsY - 54, 11, true);
$pdf->text(normalize_vin($request['vin']), $rightValueX, $detailsY - 54);

$pdf->setCursor(54, 280);
$pdf->line('Sincerely,');
$pdf->blank(22);
$pdf->line($clerkName);
if ($clerkEmail !== '') {
    $pdf->line($clerkEmail);
}
$pdf->line('Motor Vehicle Titles and Registration');
$pdf->line('210 Courthouse Way, Suite 150');
$pdf->line('PO Box 538');
$pdf->line('Rigby, ID 83442');

$filename = 'dmv-title-request-' . $request['id'] . '.pdf';
$pdf->output($filename);
