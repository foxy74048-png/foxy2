<?php

namespace App\Finance\Categories;

use App\Finance\Wallets\Wallet;

/**
 * Lista pronta de categorias em português, criada com a carteira (pessoal ou do negócio). A chave (system_key)
 * fica gravada para a categorização automática (fatia 4) reconhecer a categoria mesmo depois de renomeada.
 */
class DefaultCategories
{
    /** chave => [nome, [chave => nome dos filhos]] */
    public const PERSONAL = [
        'gasto' => [
            'moradia' => ['Moradia', ['aluguel' => 'Aluguel ou financiamento', 'condominio' => 'Condomínio', 'luz' => 'Luz', 'agua' => 'Água', 'gas' => 'Gás', 'internet' => 'Internet e telefone', 'manutencao_casa' => 'Manutenção da casa']],
            'mercado' => ['Mercado', []],
            'alimentacao' => ['Comer fora', ['delivery' => 'Delivery', 'restaurante' => 'Restaurante', 'lanche' => 'Lanche e café']],
            'transporte' => ['Transporte', ['combustivel' => 'Combustível', 'app_transporte' => 'Uber / 99 / táxi', 'onibus' => 'Ônibus e metrô', 'estacionamento' => 'Estacionamento e pedágio', 'carro' => 'Manutenção do carro', 'ipva' => 'IPVA, seguro e licenciamento']],
            'saude' => ['Saúde', ['farmacia' => 'Farmácia', 'plano_saude' => 'Plano de saúde', 'consultas' => 'Consultas e exames', 'academia' => 'Academia e esporte']],
            'educacao' => ['Educação', ['escola' => 'Escola e faculdade', 'cursos' => 'Cursos', 'livros' => 'Livros e material']],
            'assinaturas' => ['Assinaturas', ['streaming' => 'Streaming', 'apps' => 'Apps e serviços']],
            'lazer' => ['Lazer', ['viagem' => 'Viagem', 'passeios' => 'Passeios e eventos', 'hobbies' => 'Hobbies e jogos']],
            'compras' => ['Compras', ['roupas' => 'Roupas e calçados', 'casa' => 'Casa e decoração', 'eletronicos' => 'Eletrônicos']],
            'cuidados' => ['Cuidados pessoais', ['beleza' => 'Salão e beleza']],
            'filhos' => ['Filhos', []],
            'pets' => ['Pets', []],
            'taxas' => ['Taxas e juros', ['tarifas' => 'Tarifas bancárias', 'juros' => 'Juros e multas', 'anuidade' => 'Anuidade do cartão']],
            'impostos' => ['Impostos', []],
            'presentes' => ['Presentes e doações', []],
            'outros_gastos' => ['Outros gastos', []],
        ],
        'recebimento' => [
            'salario' => ['Salário', ['decimo_terceiro' => '13º e férias']],
            'renda_extra' => ['Renda extra e freelas', []],
            'pro_labore' => ['Pró-labore e retiradas', []],
            'rendimentos' => ['Rendimentos', []],
            'reembolso' => ['Reembolsos', []],
            'presentes_recebidos' => ['Presentes recebidos', []],
            'outros_recebimentos' => ['Outros recebimentos', []],
        ],
    ];

    public const BUSINESS = [
        'gasto' => [
            'mercadoria' => ['Mercadoria e fornecedores', []],
            'material' => ['Material e insumos', []],
            'aluguel_negocio' => ['Aluguel e espaço', []],
            'contas_negocio' => ['Contas (luz, água, internet)', []],
            'marketing' => ['Marketing e anúncios', []],
            'sistemas' => ['Sistemas e assinaturas', []],
            'taxas_negocio' => ['Taxas e tarifas', ['maquininha' => 'Maquininha e taxas de venda', 'tarifas_negocio' => 'Tarifas bancárias']],
            'impostos_negocio' => ['Impostos', ['das' => 'DAS (MEI)']],
            'frete' => ['Transporte e frete', []],
            'equipe' => ['Pessoas e serviços', ['funcionarios' => 'Funcionários', 'freelancers' => 'Freelancers e prestadores', 'contador' => 'Contador']],
            'equipamentos' => ['Equipamentos', []],
            'outros_negocio' => ['Outros gastos do negócio', []],
        ],
        'recebimento' => [
            'vendas' => ['Vendas', []],
            'servicos' => ['Serviços', []],
            'aporte' => ['Aporte de sócio', []],
            'outros_recebimentos_negocio' => ['Outros recebimentos', []],
        ],
    ];

    /** Cria a lista pronta na carteira do contexto (não repete se já existir). */
    public static function seed(Wallet $wallet): void
    {
        if (Category::query()->exists()) {
            return;
        }

        foreach ($wallet->isBusiness() ? self::BUSINESS : self::PERSONAL as $kind => $roots) {
            foreach ($roots as $key => [$name, $children]) {
                $root = Category::create(['kind' => $kind, 'name' => $name, 'system_key' => $key]);
                foreach ($children as $childKey => $childName) {
                    Category::create(['kind' => $kind, 'name' => $childName, 'system_key' => $childKey, 'parent_id' => $root->id]);
                }
            }
        }
    }
}
