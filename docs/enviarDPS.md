# GERAR NOTA

## enviarDPS
Gerar uma NFS-e.

```php
    require '../../../vendor/autoload.php';
    use Divulgueregional\ApiNfse\NFSeNacional;

    // 1. Configurações e Certificado
    $config = [
        'cert_path' => __DIR__ . '/../certs/cert_empresa.pfx',
        'cert_password' => '123456'
    ];

    $tpAmb = 2; // 2 = Homologação, 1 - produção
    $Serie = 10;
    $Município = '5208707';
    $CNPJ = '12345678912345';
    $Numero = 5;

    // 2. Preparar os dados para a nota
    $dados = [
        'idDps' => $nfse->gerarIdDPS($Município , $CNPJ, $Serie, $Numero),
        'dhEmi' => date('Y-m-d\TH:i:sP', strtotime('-1 minute')),
        'verAplic' => 'SaaS_1.0',
        'serie' => $Serie,
        'nDps'  => $Numero,
        'dCompet' => date('Y-m-d'),
        'tpEmit' => '1',
        'cLocEmi' => $Município,
        
        'prestador' => [
            'cnpj' => $CNPJ,
            'fone' => '',
            'email' => '',
            'regTrib' => [
                'opSimpNac' => 3, // Situação perante Simples Nacional: 1 - Não Optante; 2 - Optante - Microempreendedor Individual (MEI); 3 - Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP);
                'regApTribSN' => 1, // Regime de Apuração Tributária pelo Simples Nacional. 1 – Regime de apuração dos tributos pelo SN; 2 – Regime de apuração dos tributos federais pelo SN e o ISSQN pela NFS-e conforme respectiva legislação municipal; 3 – Regime de apuração dos tributos federais e municipal pela NFS-e conforme respectivas legilações federal e municipal de cada tributo;"
                'regEspTrib' => 0 // Tipos de Regimes: 0 - Nenhum; 1 - Ato Cooperado (Cooperativa); 2 - Estimativa; 3 - Microempresa Municipal; 4 - Notário ou Registrador; 5 - Profissional Autônomo; 6 - Sociedade de Profissionais;"

            ]
        ],
        
        'tomador' => [
            'cnpj' => '',
            'xNome' => '',
            'fone' => '6299999999',
            'email' => '',
            'endereco' => [
                'cMun' => '5208707',
                'cep' => '74275050',
                'xLgr' => 'Rua C136',
                'nro' => 'SN',
                'xBairro' => 'Jardim America'
            ],
        ],
        
        'servico' => [
            'cLocPrestacao' => '5208707',
            'cTribNac' => '010701', // 010701 - Suporte técnico, manutenção e outros serv. em TI
            'xDescServ' => 'SUPORTE E MANUTENCAO - MENSALIDADE',
            'xInfComp' => '{"infAdicLT":"5208707","infAdicES":"N"}',
            'cIntContrib' => 'ID_VENDA_123', // Código interno do contribuinte - Utilizado para identificação da DPS no Sistema interno do Contribuinte
        ],
        
        'valores' => [
            'vServ' => 120.00,
            'vDescIncond' => 0.00, // Prevenção para descontos
            'tribISSQN' => 1,
            'tpRetISSQN' => 1, // ISS devido ao próprio município
            'pAliq' => 0.00,    // Verifique se em homologação pode ser 0 ou se precisa de uma alíquota real (ex: 2.00)
            'pTotTribSN' => 0.00
        ]
    ];

    try {
        $nfse = new NFSeNacional($config, $tpAmb);

        // 3. Gerar o XML
        // 3.1. Monta o XML bruto
        $xmlBruto = $nfse->montarXmlDPS($dados);

        // 3.2. Definir o nome do arquivo
        // $nomeArquivo = $dados['idDps'] . "_bruto.xml";

        // 3.3. Salvar na pasta atual
        // file_put_contents(__DIR__ . '/xml/' . $nomeArquivo, $xmlBruto);

        // 3.4 abrir no navegador
        // header('Content-Type: application/xml; charset=utf-8');
        // echo $xmlBruto;
        // exit;

        // 4. Aplica a assinatura digital (NOVO)
        $xmlAssinado = $nfse->assinarXML($xmlBruto);
        // header('Content-Type: application/xml; charset=utf-8');
        // echo $xmlAssinado;
        // exit;

        // 5. Envia para a API Nacional
        $retorno = $nfse->enviarDPS($xmlAssinado);

        echo "Resultado do Envio:";
        echo "<pre>";
        print_r($retorno);
        echo "</pre>";


    } catch (Exception $e) {
        echo "Erro: " . $e->getMessage();
    }
```