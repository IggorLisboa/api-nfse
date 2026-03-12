# OBTER PDF NSF-e Nacional

## PDF
Busca o pdf da nota de serviço no Ambiente Nacional.

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

    $retorno = $nfse->downloadDANFSe($chaveAcesso);

    $pdfContent = $retorno['pdfContent']; // pdf
    $nomeArquivo = "{$chaveAcesso}.pdf";

    // --- BLOCO PARA SALVAR LOCALMENTE ---
    $diretorio = __DIR__ . '/pdf/'; // Define o caminho da pasta
    
    // Cria a pasta se ela não existir (com permissão de escrita)
    if (!is_dir($diretorio)) {
        mkdir($diretorio, 0775, true);
    }


    // $modo = 'attachment'; // faz download
    $modo = 'inline'; // abre no navegador

    ob_end_clean();
    header('Content-Type: application/pdf');
    header("Content-Disposition: $modo; filename=\"{$nomeArquivo}\"");

    echo $pdfContent;
    exit;
```