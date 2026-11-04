<?php

namespace App\Ai\Providers;

/**
 * O provedor de IA recusou por saldo ou limite de gasto (ex.: crédito da Anthropic acabou). Diferente de "caiu":
 * só volta quando alguém recarrega ou sobe o limite — por isso o produto avisa o dono com essa causa.
 */
class ProviderOutOfCredit extends ProviderUnavailable {}
