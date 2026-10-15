<?php

namespace App\Services\Schools;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class TranscriptPdf
{
    public function render(array $snapshot): string
    {
        $temp=storage_path('app/mpdf-transcripts');
        if (!is_dir($temp)) mkdir($temp,0755,true);
        $pdf=new Mpdf(['format'=>'Letter','margin_top'=>14,'margin_bottom'=>16,'margin_left'=>14,'margin_right'=>14,'tempDir'=>$temp,'autoScriptToLang'=>true,'autoLangToFont'=>true]);
        $pdf->SetTitle($snapshot['designation'].' transcript'); $pdf->SetAuthor($snapshot['school']['name']);
        if ($snapshot['designation']==='Unofficial') { $pdf->SetWatermarkText('Unofficial'); $pdf->showWatermarkText=true; }
        $pdf->WriteHTML(view('pdf.transcript',['document'=>$snapshot])->render());
        return $pdf->Output('',Destination::STRING_RETURN);
    }
}
