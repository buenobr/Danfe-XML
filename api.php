<?php
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
const PROVIDER_URL = 'https://consultadanfe.com/api/v1/consulta';
function fail(string $message,int $status=502): never {
 http_response_code($status);
 header('Content-Type: application/json; charset=utf-8');
 echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
 exit;
}
function modelOf(string $key): string {
 $model=substr($key,20,2);
 return $model==='65'?'NFC-e':($model==='55'?'NF-e':'Outro');
}
function nfceOfficialUrl(string $key): ?string {
 // Public SEFAZ consultation for RS (UF 43), using only the official state site.
 // For other states the user should use the official URL printed on the receipt.
 $uf=substr($key,0,2);
 if($uf==='43')return 'https://www.sefaz.rs.gov.br/NFE/NFE-NFC.aspx';
 return null;
}
const SEFAZ_RS_BASE = 'https://www.sefaz.rs.gov.br/ASP/AAE_ROOT/NFE/';
function sefazRequest(string $path,string $cookie,?array $post=null):string {
 $url=SEFAZ_RS_BASE.$path;
 $ch=curl_init($url);
 $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>35,
  CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
  CURLOPT_ENCODING=>'',CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,
  CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; DanfeXML/1.0)',
  CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml','Accept-Language: pt-BR,pt;q=0.9']];
 if($post!==null){$opts[CURLOPT_POST]=true;
  $opts[CURLOPT_POSTFIELDS]=http_build_query($post);
  $opts[CURLOPT_HTTPHEADER]=['Content-Type: application/x-www-form-urlencoded',
   'Accept: text/html,application/xhtml+xml','Referer: '.SEFAZ_RS_BASE.'SAT-WEB-NFE-NFC_1.asp'];}
 curl_setopt_array($ch,$opts);
 $html=curl_exec($ch);$err=curl_error($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
 curl_close($ch);
 if(!is_string($html)||$status!==200)fail('A SEFAZ-RS não respondeu à consulta (HTTP '.$status.'). '.$err);
 if(strlen($html)>3*1024*1024)fail('A resposta da SEFAZ excedeu o limite permitido.');
 return $html;
}
function nfceFetch(string $key):DOMDocument {
 if(substr($key,0,2)!=='43')fail('Consulta de NFC-e disponível somente para o Rio Grande do Sul (UF 43).',422);
 if(!function_exists('curl_init')||!class_exists('DOMDocument'))fail('O PHP precisa das extensões cURL e DOM.');
 $cookie=tempnam(sys_get_temp_dir(),'dnfce');
 if($cookie===false)fail('Não foi possível iniciar sessão temporária com a SEFAZ.');
 try{
  sefazRequest('SAT-WEB-NFE-NFC_1.asp',$cookie);
  $html=sefazRequest('SAT-WEB-NFE-NFC_2.asp',$cookie,['chaveNFe'=>$key,'HML'=>'false']);
 }finally{@unlink($cookie);}
 // The SEFAZ legacy endpoint returns ISO-8859-1 / Windows-1252 HTML.
 $charset=preg_match('/charset\s*=\s*["\']?utf-?8/i',substr($html,0,2000))?'UTF-8':'Windows-1252';
 if($charset!=='UTF-8') {
  if(function_exists('mb_convert_encoding'))$html=mb_convert_encoding($html,'UTF-8',$charset);
  elseif(function_exists('iconv'))$html=iconv($charset,'UTF-8//IGNORE',$html);
  else fail('Conversão de caracteres indisponível no servidor.');
 }
 // Ensure the DOM parser uses UTF-8, regardless of the legacy page meta tag.
 $html=preg_replace('/charset\s*=\s*[\"\']?[^\"\'\s>]+/i','charset=UTF-8',$html);
 $doc=new DOMDocument();$old=libxml_use_internal_errors(true);
 $loaded=$doc->loadHTML('<?xml encoding="UTF-8"?>'.$html,LIBXML_NONET|LIBXML_NOWARNING|LIBXML_NOERROR);
 libxml_clear_errors();libxml_use_internal_errors($old);
 if(!$loaded)fail('Não foi possível interpretar a resposta da SEFAZ.');
 $xpath=new DOMXPath($doc);
 foreach($xpath->query('//script|//style|//noscript') as $n)$n->parentNode?->removeChild($n);
 $text=preg_replace('/\s+/u',' ',html_entity_decode($doc->textContent,ENT_QUOTES|ENT_HTML5,'UTF-8'));
 if(!is_string($text))fail('Não foi possível validar a resposta fiscal.');
 if(stripos($text,'DANFE NFC-e')===false || stripos($text,'Protocolo')===false || stripos($text,'CONSULTA DA NFC-e')===false)
   fail('A SEFAZ não retornou um DANFE NFC-e completo. Verifique a chave e tente novamente.',422);
 // The document can display the access key with spaces or HTML formatting.
 $digits=preg_replace('/\D+/','',$text);
 if(!str_contains($digits,$key))fail('A chave retornada pela SEFAZ não corresponde à chave solicitada.',422);
 return $doc;
}
function nfcePrintable(string $key):string {
 $doc=nfceFetch($key);
 $xp=new DOMXPath($doc);
 $body=$doc->getElementsByTagName('body')->item(0);
 if(!$body)fail('A SEFAZ não retornou um documento para impressão.');
 // SEFAZ legacy HTML embeds malformed JavaScript text before the receipt.
 // Locate the receipt itself, never render the entire third-party body.
 $candidates=[];
 foreach($xp->query('//table') as $table){
  $t=preg_replace('/\s+/u',' ',(string)$table->textContent);
  if(stripos($t,'DANFE NFC-e')!==false && stripos($t,'Valor total')!==false && stripos($t,'Protocolo')!==false)
   $candidates[]=$table;
 }
 usort($candidates,static fn($a,$b)=>mb_strlen($a->textContent)<=>mb_strlen($b->textContent));
 $receipt=$candidates[0]??null;
 if(!$receipt){
  // Older SEFAZ layouts split the receipt into multiple adjacent tables.
  // Select the first container holding the document, but not its entire page.
  foreach($xp->query('//*[self::div or self::td]') as $node){
   $t=(string)$node->textContent;
   if(stripos($t,'DANFE NFC-e')!==false && stripos($t,'Valor total')!==false && stripos($t,'Protocolo')!==false){
    if(!$receipt||mb_strlen($t)<mb_strlen($receipt->textContent))$receipt=$node;
   }
  }
 }
 if(!$receipt)fail('Não foi possível isolar o DANFE NFC-e na resposta da SEFAZ.',422);
 $allowed=['table','thead','tbody','tfoot','tr','td','th','div','span','p','br','b','strong','i','em','u','small','big','center','hr','h1','h2','h3','font','sup','sub'];
 $attrs=['align','valign','width','colspan','rowspan','cellspacing','cellpadding','border'];
 $out=new DOMDocument('1.0','UTF-8');
 $wrap=$out->createElement('div');$wrap->setAttribute('class','receipt');$out->appendChild($wrap);
 $copy=function(DOMNode $node,DOMNode $parent)use(&$copy,$out,$allowed,$attrs):void {
  if($node instanceof DOMText){
   $value=$node->nodeValue;
   if(preg_match('/document\.write|window\.open|function\s+\w+\s*\(|SAT-WEB-NFE|window\.location|var\s+\w+\s*=/i',$value))return;
   $parent->appendChild($out->createTextNode($value));return;
  }
  if(!$node instanceof DOMElement)return;
  $tag=strtolower($node->tagName);
  if(in_array($tag,['script','style','noscript','iframe','object','embed','svg','form','input','button','select','textarea','link','meta','img'],true))return;
  if($tag==='a'){$target=$parent;}elseif(in_array($tag,$allowed,true)){
   $target=$out->createElement($tag);$parent->appendChild($target);
   foreach($attrs as $name)if($node->hasAttribute($name)){
    $value=trim($node->getAttribute($name));
    if(preg_match('/^[a-zA-Z0-9#%.,_ -]{1,50}$/D',$value))$target->setAttribute($name,$value);
   }
  }else{$target=$parent;}
  foreach($node->childNodes as $child)$copy($child,$target);
 };
 $copy($receipt,$wrap);
 $safe=$out->saveHTML($wrap);
 $safeKey=htmlEscape($key);
 return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>DANFE NFC-e '.$safeKey.'</title><link rel="stylesheet" href="/nfce-print.css?v=4"></head><body><header class="toolbar"><div><strong>DANFE NFC-e</strong><span>Consulta pública SEFAZ-RS · Representação para impressão</span></div><div class="buttons"><button type="button" id="print-button">Imprimir / Salvar em PDF</button><a href="/" class="back">Voltar</a></div></header><main class="page"><p class="notice">Dados consultados na SEFAZ-RS. Para gerar o PDF, selecione “Salvar como PDF” na janela de impressão.</p>'.$safe.'</main><script src="/nfce-print.js?v=4" defer></script></body></html>';
}
function keyCheck($raw): string {
 $key=preg_replace('/\D/','',(string)$raw);
 if (!preg_match('/^\d{44}$/D',$key)) fail('A chave deve conter exatamente 44 dígitos.',400);
 $sum=0;$w=2;
 for($i=42;$i>=0;$i--){$sum+=(int)$key[$i]*$w;$w=$w===9?2:$w+1;}
 $r=$sum%11;$dv=($r===0||$r===1)?0:11-$r;
 if($dv!==(int)$key[43]) fail('Dígito verificador inválido.',400);
 return $key;
}
function provider(string $key):array {
 if(!function_exists('curl_init')) fail('O servidor PHP não possui cURL habilitado.');
 $ch=curl_init(PROVIDER_URL);
 curl_setopt_array($ch,[
  CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['chave'=>$key]),
  CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
  CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>45,
  CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
  CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
  CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'Danfe-XML/1.0',
 ]);
 $body=curl_exec($ch);$error=curl_error($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
 curl_close($ch);
 if($body===false) fail('Não foi possível conectar à ConsultaDANFE: '.$error);
 if(strlen($body)>18*1024*1024) fail('Resposta do provedor excedeu o tamanho permitido.');
 $data=json_decode($body,true);
 if(!is_array($data)) fail('A API não retornou JSON válido (HTTP '.$code.').');
 if($code<200||$code>=300||!empty($data['error'])||!empty($data['erro'])||($data['success']??true)===false)
   fail((string)($data['mensagem']??$data['message']??$data['error']??$data['erro']??'Consulta recusada pelo provedor.'),502);
 return $data;
}
function uploadXml(string $key):string {
 if(!isset($_FILES['xml'])||!is_array($_FILES['xml']))fail('Selecione um arquivo XML.',400);
 $file=$_FILES['xml'];
 if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)fail('Não foi possível receber o XML enviado.',400);
 if(($file['size']??0)<1||$file['size']>5*1024*1024)fail('O XML deve ter no máximo 5 MB.',413);
 $tmp=$file['tmp_name']??'';
 if(!is_string($tmp)||!is_uploaded_file($tmp))fail('Envio de arquivo inválido.',400);
 $xml=file_get_contents($tmp);
 if(!is_string($xml)||strlen($xml)>5*1024*1024)fail('Não foi possível ler o XML enviado.',400);
 $doc=parseXml($xml); $root=$doc->documentElement;
 if(!$root||$root->localName!=='nfeProc')fail('Envie o XML autorizado completo (nfeProc). XML de evento ou NF-e sem protocolo não gera DANFE.',422);
 $xp=new DOMXPath($doc); $inf=$xp->query('//*[local-name()="infNFe"]')->item(0);
 if(!$inf instanceof DOMElement)fail('Dados da NF-e não encontrados no XML.',422);
 $id=$inf->getAttribute('Id'); $xmlKey=substr($id,0,3)==='NFe'?substr($id,3):'';
 if(!preg_match('/^\d{44}$/D',$xmlKey))fail('A chave de acesso não foi encontrada no XML.',422);
 if($xmlKey!==$key)fail('A chave do XML não corresponde à chave informada.',422);
 if(substr($key,20,2)!=='55')fail('O XML enviado não é de uma NF-e modelo 55.',422);
 if($root->localName!=='nfeProc')fail('O XML contém somente os dados da NF-e, sem protocolo de autorização. Envie o XML autorizado (nfeProc).',422);
 $prot=$xp->query('//*[local-name()="protNFe"]/*[local-name()="infProt"]')->item(0);
 if(!$prot instanceof DOMElement||field($prot,'chNFe')!==$key||field($prot,'cStat')!=='100')fail('O XML não comprova uma NF-e autorizada para esta chave.',422);
 return $xml;
}
function decoded($raw):?string {
 if(!is_string($raw)||trim($raw)==='')return null;
 $clean=preg_replace('/^data:[^;]+;base64,/i','',$raw);
 $clean=preg_replace('/\s+/','',$clean);
 if(!preg_match('~^[A-Za-z0-9+/]*={0,2}$~D',$clean)) fail('Arquivo Base64 inválido.');
 $bytes=base64_decode($clean,true);
 if($bytes===false||strlen($bytes)>15*1024*1024) fail('Arquivo retornado inválido ou muito grande.');
 return $bytes;
}
function parseXml(string $xml):DOMDocument {
 if(substr(ltrim($xml),0,1)!=='<'||preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))fail('XML inválido ou inseguro.');
 $doc=new DOMDocument('1.0','UTF-8');
 $prev=libxml_use_internal_errors(true);
 $ok=$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS);
 libxml_clear_errors();libxml_use_internal_errors($prev);
 if(!$ok)fail('XML retornado não pôde ser interpretado.');
 return $doc;
}
function firstChild(DOMElement $parent,string $name):?DOMElement {
 foreach($parent->childNodes as $node)if($node instanceof DOMElement&&$node->localName===$name)return $node;
 return null;
}
function children(DOMElement $parent,string $name):array {
 $res=[];foreach($parent->childNodes as $node)if($node instanceof DOMElement&&$node->localName===$name)$res[]=$node;
 return $res;
}
function field(?DOMElement $parent,string $name,string $default=''):string {
 if(!$parent)return $default;
 $node=firstChild($parent,$name);
 return $node?trim($node->textContent):$default;
}
function orderChildren(DOMElement $parent,array $orders):void {
 foreach(iterator_to_array($parent->childNodes) as $child)
   if($child instanceof DOMElement)orderChildren($child,$orders);
 $list=[];
 foreach($parent->childNodes as $child)if($child instanceof DOMElement)$list[]=$child;
 $order=$orders[$parent->localName]??[];
 $rank=array_flip($order);
 $original=[];
 foreach($list as $i=>$node)$original[spl_object_id($node)]=$i;
 usort($list,static function($a,$b)use($rank,$original){
   $ra=$rank[$a->localName]??100000;$rb=$rank[$b->localName]??100000;
   return ($ra<=>$rb)?:($original[spl_object_id($a)]<=>$original[spl_object_id($b)]);
 });
 foreach($list as $child)$parent->appendChild($child);
}
function normalized(string $xml,string $key):?string {
 $doc=parseXml($xml);
 $root=$doc->documentElement;
 if(!$root||$root->localName!=='nfeProc')return null;
 $nfe=firstChild($root,'NFe');$prot=firstChild($root,'protNFe');
 $info=$nfe?firstChild($nfe,'infNFe'):null;
 $infProt=$prot?firstChild($prot,'infProt'):null;
 if(!$nfe||!$prot||!$info||!$infProt)return null;
 if($info->getAttribute('Id')!=='NFe'.$key||field($infProt,'chNFe')!==$key)return null;
 if(firstChild($nfe,'Signature'))return null; // Never rewrite signed documents.
 $root->setAttribute('versao',$root->getAttribute('versao')?:field($root,'versao','4.00'));
 $info->setAttribute('versao',$info->getAttribute('versao')?:field($info,'versao','4.00'));
 $prot->setAttribute('versao',$prot->getAttribute('versao')?:'4.00');
 foreach([[$root,'versao'],[$info,'versao'],[$info,'Id']] as [$node,$tag]){
   foreach(children($node,$tag) as $bad)$node->removeChild($bad);
 }
 $dets=children($info,'det');
 if(!$dets)return null;
 foreach($dets as $i=>$det){
   $det->setAttribute('nItem',$det->getAttribute('nItem')?:field($det,'nItem',(string)($i+1)));
   foreach(children($det,'nItem') as $bad)$det->removeChild($bad);
 }
 $orders=json_decode(file_get_contents(__DIR__.'/xml-order.json'),true);
 if(!is_array($orders))fail('Configuração XML indisponível.');
 orderChildren($root,$orders);
 $doc->formatOutput=false;
 return $doc->saveXML();
}
function htmlEscape(string $s):string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function currency(string $s):string {return is_numeric($s)?'R$ '.number_format((float)$s,2,',','.'):'—';}
function noteHtml(string $xml,string $key):string {
 $doc=parseXml($xml);
 $xp=new DOMXPath($doc);
 $xp->registerNamespace('n','http://www.portalfiscal.inf.br/nfe');
 $inf=$xp->query('//*[local-name()="infNFe"]')->item(0);
 if(!$inf instanceof DOMElement)fail('Dados da NF-e não encontrados.');
 $ide=firstChild($inf,'ide');$emit=firstChild($inf,'emit');$dest=firstChild($inf,'dest');
 $total=firstChild(firstChild($inf,'total')??$inf,'ICMSTot');
 $number=field($ide,'nNF','—');$serie=field($ide,'serie','—');
 $address=function(?DOMElement $node):string {
   if(!$node)return '—';$parts=[];
   foreach(['xLgr','nro','xBairro','xMun','UF','CEP'] as $k){$v=field($node,$k);if($v!=='')$parts[]=$v;}
   return implode(', ',$parts)?:'—';
 };
 $rows='';
 foreach(children($inf,'det') as $item){
  $p=firstChild($item,'prod');if(!$p)continue;
  $values=[field($p,'cProd'),field($p,'xProd'),field($p,'NCM'),field($p,'CFOP'),field($p,'uCom'),field($p,'qCom'),currency(field($p,'vUnCom')),currency(field($p,'vProd'))];
  $rows.='<tr>'.implode('',array_map(static fn($v)=>'<td>'.htmlEscape($v?:'—').'</td>',$values)).'</tr>';
 }
 $box=static fn(string $label,string $value):string=>'<div class="field"><b>'.htmlEscape($label).'</b>'.htmlEscape($value?:'—').'</div>';
 $pdf='/api.php?action=pdf&amp;chave='.$key;
 $content='<section><h2>Chave de acesso</h2><p class="key">'.htmlEscape($key).'</p></section>';
 $content.='<section><h2>Emitente</h2><div class="grid">'.$box('Razão social',field($emit,'xNome')).$box('CNPJ',field($emit,'CNPJ')).$box('Endereço',$address($emit?firstChild($emit,'enderEmit'):null)).'</div></section>';
 $content.='<section><h2>Destinatário</h2><div class="grid">'.$box('Nome',field($dest,'xNome')).$box('CNPJ/CPF',field($dest,'CNPJ',field($dest,'CPF'))).$box('Endereço',$address($dest?firstChild($dest,'enderDest'):null)).'</div></section>';
 $content.='<section><h2>Itens</h2><div class="table"><table><thead><tr><th>Código</th><th>Descrição</th><th>NCM</th><th>CFOP</th><th>Un.</th><th>Qtd.</th><th>Unitário</th><th>Total</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
 $content.='<section><h2>Totais</h2><div class="grid">'.$box('ICMS',currency(field($total,'vICMS'))).$box('IPI',currency(field($total,'vIPI'))).$box('Valor da nota',currency(field($total,'vNF'))).'</div></section>';
 return '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>NF-e '.htmlEscape($number).'</title><style>*{box-sizing:border-box}body{margin:0;background:#f2f6fb;font:14px/1.5 Arial,sans-serif;color:#142238}main{max-width:1100px;margin:20px auto;background:white;padding:24px;border:1px solid #d9e3ef}h1{margin:0}h2{font-size:16px}section{border:1px solid #d9e3ef;padding:15px;margin:15px 0}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.field{padding:10px;border:1px solid #e0e7f1;overflow-wrap:anywhere}.field b{display:block;color:#687e98;font-size:11px}.key{overflow-wrap:anywhere;font-family:monospace}.table{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:750px}th,td{padding:8px;border:1px solid #d9e3ef;text-align:left}th{background:#f4f8fd}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}.actions a,.actions button{background:#176be8;color:white;border:0;padding:10px 15px;border-radius:8px;text-decoration:none;cursor:pointer}@media(max-width:700px){main{margin:8px;padding:12px}.grid{grid-template-columns:1fr}}@media print{body{background:white}main{border:0;margin:0}.actions{display:none}}</style></head><body><main><div class="actions"><button onclick="window.print()">Imprimir</button><a href="'.$pdf.'">Baixar DANFE em PDF</a></div><h1>Visualização da NF-e nº '.htmlEscape($number).' / Série '.htmlEscape($serie).'</h1><p>Visualização de consulta. Não substitui o documento fiscal original.</p>'.$content.'</main></body></html>';
}
$action=(string)($_GET['action']??'');
if($action==='health'){
 header('Content-Type: application/json; charset=utf-8');
 echo json_encode(['ok'=>true,'php'=>PHP_VERSION,'curl'=>function_exists('curl_init'),'dom'=>class_exists('DOMDocument')]);
 exit;
}
if(!in_array($action,['consulta','pdf','xml','html','xml-danfe'],true))fail('Operação não encontrada.',404);
if($action==='xml-danfe'){
 if($_SERVER['REQUEST_METHOD']!=='POST')fail('Método inválido.',405);
 $key=keyCheck($_POST['chave']??'');
 $xml=uploadXml($key);
 header('Content-Type: text/html; charset=utf-8');
 echo noteHtml($xml,$key);exit;
}
if($action==='consulta'){
 if($_SERVER['REQUEST_METHOD']!=='POST')fail('Método inválido.',405);
 $body=file_get_contents('php://input');
 if(strlen($body)>2048)fail('Requisição muito grande.',413);
 $payload=json_decode($body,true);
 $key=keyCheck($payload['chave']??'');
}else{
 if($_SERVER['REQUEST_METHOD']!=='GET')fail('Método inválido.',405);
 $key=keyCheck($_GET['chave']??'');
}
$model=modelOf($key);
if($model==='Outro')fail('Modelo fiscal não suportado. Use NF-e (55) ou NFC-e (65).',422);
if($model==='NFC-e'){
 if($action==='xml')fail('XML não disponível para NFC-e. Utilize a impressão do DANFE.',422);
 if($action==='pdf')fail('Para salvar a NFC-e em PDF, abra a visualização e utilize Imprimir / Salvar como PDF.',422);
 if($action==='consulta'){
  // Verify actual SEFAZ document before displaying print action.
  nfceFetch($key);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true,'chave'=>$key,'tipo'=>'NFC-e',
   'arquivos'=>['pdf'=>false,'xml'=>false,'html'=>true],
   'consulta_oficial'=>nfceOfficialUrl($key)],JSON_UNESCAPED_UNICODE);
  exit;
 }
 header('Content-Type: text/html; charset=utf-8');
 echo nfcePrintable($key);exit;
}
try {$data=provider($key);} catch (Throwable $e) {
 if($action==='consulta') {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(422);
  echo json_encode(['error'=>'A API recusou esta chave. Para notas antigas, envie o XML autorizado completo.','code'=>'XML_REQUIRED'],JSON_UNESCAPED_UNICODE);
  exit;
 }
 throw $e;
}
if($action==='pdf'){
 $pdf=decoded($data['pdf_base64']??null);
 if(!$pdf||substr($pdf,0,4)!=='%PDF')fail('O provedor não retornou PDF válido.');
 header('Content-Type: application/pdf');
 header('Content-Disposition: attachment; filename="danfe-'.$key.'.pdf"');
 echo $pdf;exit;
}
$xml=decoded($data['xml_base64']??null);
if($action==='consulta'){
 $pdf=decoded($data['pdf_base64']??null);
 if(!$pdf&&!$xml)fail('A API não retornou PDF nem XML.');
 $canXml=false;
 if($xml){try{$canXml=normalized($xml,$key)!==null;}catch(Throwable $e){$canXml=false;}}
 header('Content-Type: application/json; charset=utf-8');
 echo json_encode(['ok'=>true,'chave'=>$key,'tipo'=>$data['tipo']??'NF-e','arquivos'=>['pdf'=>($pdf!==null&&substr($pdf,0,4)==='%PDF'),'xml'=>$canXml]]);
 exit;
}
if(!$xml)fail('O provedor não retornou XML.');
if($action==='xml'){
 $normalized=normalized($xml,$key);
 if(!$normalized)fail('O XML recebido não pode ser reconstruído com segurança.',422);
 header('Content-Type: application/xml; charset=utf-8');
 header('Content-Disposition: attachment; filename="consulta-nfe-'.$key.'-sem-assinatura.xml"');
 echo $normalized;exit;
}
header('Content-Type: text/html; charset=utf-8');
echo noteHtml($xml,$key);

