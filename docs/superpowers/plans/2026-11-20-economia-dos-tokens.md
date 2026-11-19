# Economia dos tokens (fórmula, custo real, franquia no plano e pacotes) — plano

> Execução inline, tarefa por tarefa, com teste antes do código. Commit e push na `main` juntos.

**Meta:** o super admin sabe quanto vale de fato um token: preço por modelo → fórmula → custo e preço de venda; cada resposta da IA guarda o custo real; a franquia do plano mostra seu custo; pacotes adicionais têm catálogo com preço e margem, e a empresa os pede pelo painel (o super admin libera enquanto não há gateway).

## A fórmula
- **1 token equivalente = 1 token de entrada, sem cache, do modelo base.**
- Preço por 1 milhão de tokens, em dólar, por modelo, com data de vigência: entrada, entrada em cache, saída (o raciocínio conta como saída).
- Tokens equivalentes de uma resposta = `ceil(((entrada − cache) × P_ent + cache × P_cache + saída × P_saída) / P_ent do modelo base)`.
- Custo em reais = `(mesma soma em dólar) × câmbio`.
- Valor de 1 milhão de tokens equivalentes: custo = `P_ent do base × câmbio`; venda = custo × (1 + margem%) × (1 + taxas%).
- Enquanto a tabela de preços não for salva, vale o cálculo antigo (pesos manuais): nada muda sozinho em quem já usa.
- Valores iniciais para preencher a tela (informados pelo dono, **a confirmar na conta da OpenAI**): GPT-6 Luna US$ 0,10 / 0,01 / 0,50; GPT-5.6 Luna US$ 0,20 / (cache a informar) / 1,20; câmbio 5,90; margem 100%; taxas 0%.

## Regras fixas
- Só super administrador mexe em preço, margem, pacotes e pedidos. A empresa só vê pacotes ativos e faz pedidos dela.
- Mudar preço nunca reescreve o histórico: cada resposta guarda o custo calculado com o preço da época.
- Pedido de pacote: uma liberação só (a liberação reivindica o pedido de forma atômica); o pacote entra pela carteira que já existe (validade em meses, nota com quem liberou).
- Nada de texto "envelope"; valores em reais com vírgula na tela.

## Tarefas
1. **Tabela de preços e fórmula** (`AssistantPricing`, configuração salva em `assistant.pricing`, `TokenWallet::equivalent` usa a fórmula quando o modelo tem preço). Testes: equivalentes e custo com os números reais, modelo sem preço cai no cálculo antigo, vigência por data, base ≠ modelo usado.
2. **Custo real por resposta:** colunas `cost_usd`, `fx`, `cost_brl` nas entradas de uso, preenchidas no débito (preço e câmbio da época). Testes: custo gravado, preço mudou depois não altera o histórico, modelo sem preço grava nulo.
3. **Tela "Economia dos tokens" (super admin):** formulário de preços/câmbio/margem/taxas/modelo base, valores derivados (custo e venda por 1.000 e por 1 milhão), média de tokens e custo por conversa nos últimos 30 dias, "≈ conversas" e relatório mensal por empresa (tokens, custo real, valor de venda equivalente, receita de pacotes). Testes: salvar/validar, só super admin, números do relatório.
4. **Franquia do plano:** no formulário do plano, custo máximo da franquia e % da mensalidade, com alerta acima do limite configurado. Testes.
5. **Catálogo de pacotes e pedidos:** `assistant_packages` e `assistant_package_orders`; tela do super admin (criar/editar com preço sugerido e margem; fila de pedidos com liberar/recusar); painel da empresa (pacotes ativos com preço e "≈ conversas", botão Pedir, status dos pedidos). Testes: isolamento, pedido duplicado, liberar uma vez, saldo entra com validade, recusar.
6. **Documentação, suíte completa, revisão independente, commit e push.**
