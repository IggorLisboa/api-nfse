<?php
/**
 * Exemplo de uso da classe NFSeNacional
 * Sistema Nacional NFS-e - Ambiente de Homologação
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Divulgueregional\ApiNfse\NFSeNacional;

// =============================================================================
// CONFIGURAÇÃO
// =============================================================================

// Configuração do certificado digital (A1 ou A3)
$config = [
    'cert_path' => '../certs/eduardo.pfx',  // Caminho do certificado .pfx ou .p12
    'cert_password' => '123456'         // Senha do certificado
];

// Tipo de ambiente: 1 = Produção, 2 = Homologação
$tpAmb = 2;

// Instancia a classe
$nfse = new NFSeNacional($config, $tpAmb);

// =============================================================================
// EXEMPLO 1: EMITIR NFS-e (Enviar DPS)
// =============================================================================

echo "=== EXEMPLO: Emissão de NFS-e ===\n\n";

// Dados do prestador
$prestador = [
    'cnpj' => '37 991 670/0001-27',
    'xNome' => 'EDUARDO DA SILVA GONCALVES',
    'im' => '192895',
    'fone' => '91991970568',
    'email' => 'trdudugoncalves@gmail.com',
    'telefone' => '37999167000',
    'cep' => '87704000',
    'logradouro' => 'Avenida Paraná',
    'numero' => '1000',
    'complemento' => 'Sala 101',
    'bairro' => 'Centro',
    'cod_municipio' => '4305108',
    'regTrib' => [
        'opSimpNac' => 3,      // 1=Não optante, 2=Optante ME/EPP, 3=MEI
        'regApTribSN' => 1,    // Regime de apuração (se Simples Nacional)
        'regEspTrib' => 2      // Regime especial de tributação
    ]
];

// Dados do tomador (cliente)
$tomador = [
    'CNPJ' => '13851982000177',  // ou 'cnpj' para pessoa jurídica
    'xNome' => '3SD CORTE E DOBRA LTDA',
    'endereco' => [
        'cMun' => '4305108',    // Código IBGE do município
        'cep' => '95076190',
        'xLgr' => 'JOAO BIAZUS',
        'nro' => '490',
        'xBairro' => 'SAO VIRGILIO'
    ],
    // 'fone' => '91999999999',
    // 'email' => 'cliente@email.com'
];

// Dados do serviço
$servico = [
    'cLocPrestacao' => '4305108',  // Código IBGE do local de prestação
    'cTribNac' => '160201',        // Código de tributação nacional (sem pontos)
    'xDescServ' => 'Transporte de carga',
    'xInfComp' => 'Informações complementares do serviço'
];

// Valores e tributos
$valores = [
    'vServ' => 1.00,        // Valor do serviço
    'tribISSQN' => 0.00,          // 1=Operação normal, 2=Imunidade, etc.
    'tpRetISSQN' => 0.00,         // 1=Não retido, 2=Retido pelo tomador
    'pAliq' => 0.00,           // Alíquota do ISS (%)
    'pTotTribSN' => 0.00,      // Percentual total de tributos (Simples Nacional)
    'tribFed' => [
        'piscofins' => [
            'CST' => '08'      // CST PIS/COFINS
        ]
    ]
];

// Gera o IdDPS
$cMunEmissor = '4305108';  // Código IBGE do município do emissor
$serie = 900;
$numeroDps = 32;

$idDps = $nfse->gerarIdDPS($cMunEmissor, $prestador['cnpj'], $serie, $numeroDps);
echo "IdDPS gerado: {$idDps}\n\n";

// Monta os dados da DPS
$dadosDps = [
    'idDps' => $idDps,
    'serie' => (string)$serie,
    'nDps' => (string)$numeroDps,
    'cLocEmi' => $cMunEmissor,
    'prestador' => $prestador,
    'tomador' => $tomador,
    'servico' => $servico,
    'valores' => $valores
];

// Monta o XML da DPS (sem assinatura)
$xmlDps = $nfse->montarXmlDPS($dadosDps);
echo "XML da DPS montado com sucesso!\n";
echo "Tamanho do XML: " . strlen($xmlDps) . " bytes\n\n";

// IMPORTANTE: Você precisa assinar o XML antes de enviar!
// A assinatura deve ser feita no nó 'infDPS' usando certificado digital
// Exemplo usando biblioteca de assinatura (não incluída):
// $xmlDpsAssinado = $assinador->assinarXml($xmlDps, 'infDPS');

// Para teste, vamos simular que o XML já está assinado
$xmlDpsAssinado = $xmlDps; // Substitua pelo XML assinado

// Envia a DPS para gerar a NFS-e
echo "Enviando DPS para o SEFIN Nacional...\n";
$resultado = $nfse->enviarDPS($xmlDpsAssinado);

// Exemplo de resposta de sucesso:
//*
if ($resultado['codigo'] === '000') {
    echo "NFS-e emitida com sucesso!\n";
    echo "Chave de Acesso: " . $resultado['chaveAcesso'] . "\n";
    echo "Número da NFS-e: " . $resultado['ndfse'] . "\n";
    echo "Data/Hora: " . $resultado['dataHoraProcessamento'] . "\n";
    
    // Salvar XML da NFS-e
    file_put_contents("nfse_{$resultado['chaveAcesso']}.xml", $resultado['xmlNfse']);
    
    // URL de consulta pública
    echo "URL Consulta: " . $nfse->getUrlConsultaPublica($resultado['chaveAcesso']) . "\n";
} else {
    echo "Erro ao emitir NFS-e: " . $resultado['mensagem'] . "\n";
}
//*/
die;

// =============================================================================
// EXEMPLO 2: CONSULTAR NFS-e
// =============================================================================

echo "\n=== EXEMPLO: Consulta de NFS-e ===\n\n";

$chaveAcesso = '15054862248276920001110090000003200000000032';

echo "Consultando NFS-e pela chave de acesso...\n";
// $resultado = $nfse->consultarNFSe($chaveAcesso);

/*
if ($resultado['codigo'] === '000') {
    echo "NFS-e encontrada!\n";
    echo "Número: " . $resultado['ndfse'] . "\n";
    
    // Salvar XML
    file_put_contents("nfse_consulta.xml", $resultado['xmlNfse']);
} else {
    echo "Erro: " . $resultado['mensagem'] . "\n";
}
*/

// =============================================================================
// EXEMPLO 3: CONSULTAR DPS
// =============================================================================

echo "\n=== EXEMPLO: Consulta de DPS ===\n\n";

$idDpsConsulta = '150548624482769200011100900000000000000032';

echo "Consultando DPS pelo IdDPS...\n";
// $resultado = $nfse->consultarDPS($idDpsConsulta);

/*
if ($resultado['codigo'] === '000') {
    echo "DPS encontrada!\n";
    echo "Chave de Acesso da NFS-e: " . $resultado['chaveAcesso'] . "\n";
} else {
    echo "Erro: " . $resultado['mensagem'] . "\n";
}
*/

// =============================================================================
// EXEMPLO 4: CANCELAR NFS-e
// =============================================================================

echo "\n=== EXEMPLO: Cancelamento de NFS-e ===\n\n";

$chaveAcessoCancelar = '15054862248276920001110090000003200000000032';
$cnpjAutor = '44827692000111';
$cMotivo = 1;  // 1=Erro na emissão, 2=Serviço não prestado, 3=Outros
$xMotivo = 'Erro nos dados do tomador';

// Monta o XML do pedido de cancelamento (sem assinatura)
$xmlCancelamento = $nfse->montarXmlCancelamento($chaveAcessoCancelar, $cnpjAutor, $cMotivo, $xMotivo);
echo "XML de cancelamento montado!\n";

// IMPORTANTE: Assinar o XML no nó 'infPedReg' antes de enviar
// $xmlCancelamentoAssinado = $assinador->assinarXml($xmlCancelamento, 'infPedReg');

// Envia o cancelamento
// $resultado = $nfse->cancelarNFSe($chaveAcessoCancelar, $cnpjAutor, $cMotivo, $xMotivo, $xmlCancelamentoAssinado);

/*
if ($resultado['codigo'] === '000') {
    echo "NFS-e cancelada com sucesso!\n";
} else {
    echo "Erro ao cancelar: " . $resultado['mensagem'] . "\n";
}
*/

// =============================================================================
// EXEMPLO 5: DOWNLOAD DO PDF (DANFSE)
// =============================================================================

echo "\n=== EXEMPLO: Download do DANFSE (PDF) ===\n\n";

$chaveAcessoPdf = '15054862248276920001110090000003200000000032';

echo "Baixando PDF da NFS-e...\n";
// $resultado = $nfse->downloadDANFSe($chaveAcessoPdf);

/*
if ($resultado['codigo'] === '000') {
    file_put_contents("danfse_{$chaveAcessoPdf}.pdf", $resultado['pdfContent']);
    echo "PDF salvo com sucesso!\n";
} else {
    echo "Erro ao baixar PDF: " . $resultado['mensagem'] . "\n";
}
*/

// =============================================================================
// EXEMPLO 6: CONSULTAR EVENTO (Cancelamento)
// =============================================================================

echo "\n=== EXEMPLO: Consulta de Evento ===\n\n";

$chaveAcessoEvento = '15054862248276920001110090000003200000000032';
$tipoEvento = 101101;  // Cancelamento
$numSeqEvento = 1;

echo "Consultando evento de cancelamento...\n";
// $resultado = $nfse->consultarEventoNFSe($chaveAcessoEvento, $tipoEvento, $numSeqEvento);

/*
if ($resultado['codigo'] === '000') {
    echo "Evento encontrado!\n";
    foreach ($resultado['eventos'] as $evento) {
        echo "Tipo: " . $evento['tipoEvento'] . "\n";
        echo "Data: " . $evento['dataHoraRecebimento'] . "\n";
    }
} else {
    echo "Erro: " . $resultado['mensagem'] . "\n";
}
*/

echo "\n=== FIM DOS EXEMPLOS ===\n";
echo "\nNOTA: Para executar os exemplos reais, descomente as chamadas de API\n";
echo "e configure corretamente o certificado digital.\n";
