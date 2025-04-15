<?php
error_reporting(E_ALL);
ini_set('display_errors', true);

function db()
{
    static $pdo = new PDO('sqlite:local.db');
    
    return $pdo;
}

function post($url, $data)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($data) ? $data : json_encode($data));
    $ret = curl_exec($ch);
    
    return $ret ? json_decode($ret, true) : null;
}

const URL1 = "https://portal.cfm.org.br/api_rest_php/api/v1/medicos/buscar_medicos";
const URL2 = "https://portal.cfm.org.br/api_rest_php/api/v1/medicos/buscar_foto/";

function fetchCRM($uf, $crm)
{
    $param1 = [
        "useCaptchav2" => true,
        "captcha" => "",
        "medico" => [
            "nome" => "",
            "ufMedico" => "$uf",
            "crmMedico" => "$crm",
            "municipioMedico" => "",
            "tipoInscricaoMedico" => "",
            "situacaoMedico" => "",
            "detalheSituacaoMedico" => "",
            "especialidadeMedico" => "",
            "areaAtuacaoMedico" => ""
        ],
        "page" => 1,
        "pageNumber" => 1,
        "pageSize" => 10
    ];

    $dados1 = post(URL1, [$param1]);
    
    if(empty($dados1['dados']))
    {
        return null;
    }
    
    $param2 = [
        "securityHash" => $dados1['dados'][0]['SECURITYHASH'],
        "crm" => "$crm",
        "uf" => "$uf"
    ];
    
    $dados2 = post(URL2, [$param2]);
    
    if(empty($dados2['dados']))
    {
        return null;
    }
    
    $dados1 = $dados1['dados'][0];
    $dados1['dados1'] = json_encode($dados1);
    $dados2 = $dados2['dados'][0];
    $dados1['dados2'] = json_encode($dados2);
    
    $dados = array_merge($dados1, $dados2);
	$dados = array_map(fn($v) => $v ? mb_strtoupper($v) : null, $dados);
	return array_change_key_case($dados);
}

function upsert($fields)
{
    static $columns = [
        "sg_uf" => 'uf', 
		"nu_crm" => 'crm_original', 
		"nu_crm_natural" => 'crm', 
		"nm_medico" => 'nome', 
		"cod_situacao" => 'situacao_inscricao', 
        "dt_inscricao" => 'data_inscricao', 
		"tipo_inscricao" => 'tipo_inscricao', 
		"especialidade" => 'especialidade', 
        "prim_inscricao_uf" => 'primeira_inscricao',
		"obs_interdicao" => 'obs_interdicao', 
        "nm_instituicao_graduacao" => 'instituicao_graduacao',
		"dt_graduacao" => 'ano_graduacao',
        "nm_faculdade_estrangeira_graduacao" => 'instituicao_estrangeira_graduacao',
		'situacao' => 'situacao',
		"endereco" => 'endereco', 
        "telefone" => 'telefone',
		"inscricao" => 'inscricoes',
		"dados1" => 'dados1', 
		"dados2" => 'dados2', 
        "invalidado" => 'invalidado', 
		"atualizado_em" => 'atualizado_em'
    ];
	
	$uf = db()->quote(@$fields['sg_uf'] ?: @$fields['uf_crm']);
	$crm = db()->quote(@$fields['nu_crm'] ?: $fields['crm']);
	$upfields = implode(', ', array_map(fn($v) => "$v = :$v", $columns));

	$select = "SELECT * FROM cfm WHERE uf = $uf AND crm = $crm";
    $insert = 'INSERT INTO cfm ('.implode(', ', $columns).') VALUES (:'.implode(', :', $columns).')';
	$update = "UPDATE cfm SET $upfields WHERE id = :id";//"sg_uf = $uf AND nu_crm = $crm";

	$record = db()->query($select, PDO::FETCH_ASSOC)->fetch();

	if($record)
	{
		echo "\r{$fields['uf_crm']}: {$fields['crm']} - UPDATE";
		$fields = array_merge($record, $fields);
		$stmt = db()->prepare($update);
		$stmt->bindValue('id', $record['id']);
	}
	else
	{
		echo "\r{$fields['uf_crm']}: {$fields['crm']} - INSERT";
		$stmt = db()->prepare($insert);
	}
    
    foreach($columns as $name => $dbname)
    {
        if(isset($fields[$name]))
        {
			$value = $fields[$name] ? preg_replace('/^(\d\d)\/(\d\d)\/(\d{4})$/', "$3-$2-$1", $fields[$name]) : null;
            $stmt->bindValue($dbname, $value);
        }
    }

    $stmt->bindValue('invalidado', 0);
    $stmt->bindValue('atualizado_em', date(DATE_ISO8601));

    return $stmt->execute();
}

function getNextCRM($uf, $anterior = false)
{
    $uf = db()->query('SELECT * FROM crm_uf WHERE uf='.db()->quote($uf), PDO::FETCH_ASSOC)->fetch();
    
    if(!$uf)
    {
        return null;
    }
    
    if($anterior)
    {
        return $uf['anterior'] <= 0 ? null : $uf['anterior'];
    }
    
    return $uf['proximo_concluido'] == date('Y-m-d') ? null : $uf['proximo'];
}

function updateUfCRM($uf, $crm)
{
    $ufDb = db()->query('SELECT * FROM crm_uf WHERE uf = '.db()->quote($uf), PDO::FETCH_ASSOC)->fetch();
    
    if($crm === null)
    {
        db()->exec("UPDATE crm_uf SET proximo_concluido = CURRENT_DATE WHERE uf = ".db()->quote($uf));
    }
    elseif($crm < $ufDb['anterior'])
    {
        db()->exec("UPDATE crm_uf SET anterior = $crm WHERE uf = ".db()->quote($uf));
    }
    elseif($crm > $ufDb['proximo'])
    {
        db()->exec("UPDATE crm_uf SET proximo = $crm WHERE uf = ".db()->quote($uf));
    }
}

function getNextUf()
{
	$ufs = db()->query('SELECT uf FROM crm_uf ORDER BY uf', PDO::FETCH_COLUMN, 0)->fetchAll();
	
	$minuto = (int)(time() / 60);
	return $ufs ? $ufs[$minuto % count($ufs)] : null;
}

function calcularDigitoVerificador($numeroBase) {
    // Garante que o número seja tratado como string
    $numeroBase = (string)$numeroBase;
    
    // Quantidade de dígitos da base
    $numDigitos = strlen($numeroBase);
    
    // Inicializa a soma
    $soma = 0;
    
    // O peso inicial é igual a (número de dígitos + 1) e decresce até 2
    for ($i = 0; $i < $numDigitos; $i++) {
        $peso = $numDigitos + 1 - $i;
        $soma += (int)$numeroBase[$i] * $peso;
    }
    
    // Calcula o resto da divisão da soma por 11
    $resto = $soma % 11;
    
    // Calcula o dígito verificador
    $dv = 11 - $resto;
    
    // Se o resultado for 10 ou 11, o dígito verificador é 0
    if ($dv == 10 || $dv == 11) {
        $dv = 0;
    }
    
    return $dv;
}

while(true)
{
	$uf = getNextUf();

	if(!$uf)
	{
		return;
	}
	
	$crmOriginal = $crm = getNextCRM($uf);
	$anterior = !(bool)$crm;

	if($anterior)
	{
		$crmOriginal = $crm = getNextCRM($uf, $anterior);
	}

	if(!$crm)
	{
		continue;
	}
	
	if($uf == 'RJ')
	{
		$crm .= calcularDigitoVerificador($crm);
	}

	$dados = db()->query("SELECT * FROM cfm WHERE crm = $crm AND uf = ".db()->quote($uf), PDO::FETCH_ASSOC)->fetch();

	if(empty($dados) || !@$dados['dados2'])
	{
		echo "\r$uf: $crm - FETCH     ";
		$dados = fetchCRM($uf, $crm);

		if($dados)
		{
			upsert($dados);
			updateUfCRM($uf, $anterior ? $crmOriginal - 1 : $crmOriginal + 1);
		}
	}
	
	
	if(empty($dados))
	{
		echo "\r$uf: $crm - ANTERIOR";
		updateUfCRM($uf, $anterior ? $crmOriginal - 1 : null);
	}

	echo "\r$uf: $crm - ".($dados ? $dados['nome'] : '<NÃO ENCONTRADO>').PHP_EOL;

	$sleep = rand(5, 10);

	echo "\rAGUARDANDO $sleep SEGUNDOS";
	sleep($sleep);
}