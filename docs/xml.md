# OBTER XML NSF-e Nacional

## PDF
Busca o xml da nota de serviço no Ambiente Nacional.

```php
    ob_start(); 
    error_reporting(E_ALL); 
    ini_set('display_errors', 1);

    require '../../../vendor/autoload.php';
    use Divulgueregional\ApiNfse\NFSeNacional;

    // 1. Configurações e Certificado
    $config = [
        'cert_path' => __DIR__ . '/../certs/cert_empresa.pfx',
        'cert_password' => '123456'
    ];

    $tpAmb = 2; // 2= Homologação, 1= produção
    $nfse = new NFSeNacional($config, $tpAmb);
    $chaveAcesso = '';



    $pdfContent = $retorno['pdfContent']; // pdf
    $nomeArquivo = "{$chaveAcesso}.xml";

    // $modo = 'attachment'; // faz download
    $modo = 'inline'; // abre no navegador

    if (isset($retorno['codigo']) && $retorno['codigo'] == '000') {
        $dados = $retorno['dados'];

        if (!empty($dados['LoteDFe'])) {
            foreach ($dados['LoteDFe'] as $item) {
                // 1. Pega XML
                $xmlCompactado = $item['ArquivoXml'];

                // 2. Descompactar o XML (Gzip -> String)
                $xmlDecodificado = gzdecode(base64_decode($xmlCompactado));

                // 2.1. Gravar o XML na pasta local
                $nomeArquivo = $item['ChaveAcesso'] . ".xml";
                // file_put_contents(__DIR__ . "/xml/" . $nomeArquivo, $xmlDecodificado);
            }
        }

        ob_end_clean();
        header('Content-Type: application/xml');
        header("Content-Disposition: $modo; filename=\"{$nomeArquivo}\"");

        echo $xmlDecodificado;
    } else {
        ob_end_clean();
        echo "<h1>Erro mostrar XML</h1>";
        echo "<pre>";
        print_r($retorno);
        echo "</pre>";
    }
```