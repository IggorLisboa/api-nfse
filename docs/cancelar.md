# CANCELAR DA NSF-e Nacional

## Cancelamento
Via cancelar uma nota de serviço no Ambiente Nacional.

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
    $chaveAcesso = ''; // chave da nota que quer cancelar
    $cnpjAutor   = ''; // cnpj do emitente

    /* Motivos de Cancelamento (Padrão Nacional):
    1 - Erro na Emissão
    2 - Serviço não Prestado
    3 - Erro de Assinatura (ou Outros conforme o provedor)
    */
    $cMotivo = 1; 
    $xMotivo = 'Erro na digitacao do valor do servico'; // opcional

    // A função agora cuida de Gerar XML -> Assinar -> Compactar -> Enviar
    $resultado = $nfse->cancelarNFSe($chaveAcesso, $cnpjAutor, $cMotivo, $xMotivo);

    echo "<h1>Resultado do Cancelamento</h1>";
    echo "<pre>";
    print_r($resultado);
    echo "</pre>";

    if ($resultado['codigo'] === '000') {
        $xmlRetornoGZ = base64_decode($resultado['resposta']['eventoXmlGZipB64']);
        $xmlRetorno = gzdecode($xmlRetornoGZ);
        
        // Salva o protocolo de cancelamento para o cliente
        file_put_contents("cancelamento/cancelamento_{$chaveAcesso}.xml", $xmlRetorno);
        echo "Protocolo de cancelamento salvo com sucesso!";
    }