(() => {
    'use strict';

    const dadosElemento = document.getElementById('financeiroSaasChartData');
    if (!dadosElemento) {
        console.error('[ROJEX FINANCEIRO SAAS] Dados dos gráficos não foram encontrados.');
        return;
    }

    let dados;
    try {
        dados = JSON.parse(dadosElemento.textContent || '{}');
    } catch (erro) {
        console.error('[ROJEX FINANCEIRO SAAS] Dados dos gráficos inválidos.', erro);
        return;
    }

    if (typeof Chart === 'undefined') {
        console.error('[ROJEX FINANCEIRO SAAS] Chart.js não foi carregado.');
        return;
    }

    const moeda = new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    });

    const paleta = [
        '#2f7cc0',
        '#198754',
        '#dc3545',
        '#ffc107',
        '#0dcaf0',
        '#6f42c1',
        '#fd7e14',
        '#6c757d',
        '#20c997',
        '#d63384'
    ];

    const tooltipMoeda = {
        callbacks: {
            label(context) {
                const rotulo = context.dataset.label ? context.dataset.label + ': ' : '';
                return rotulo + moeda.format(Number(context.raw || 0));
            }
        }
    };

    const eixoMoeda = {
        beginAtZero: true,
        ticks: {
            callback(value) {
                return moeda.format(Number(value || 0));
            }
        }
    };

    const criar = (id, configuracao) => {
        const canvas = document.getElementById(id);
        if (!canvas) return;

        const temDados = configuracao.data.datasets.some(
            dataset => Array.isArray(dataset.data) && dataset.data.length > 0
        );

        if (!temDados) {
            canvas.parentElement.innerHTML =
                '<div class="fs-empty"><div><i class="bi bi-inbox fs-2 d-block mb-2"></i>Nenhum dado disponível para este gráfico.</div></div>';
            return;
        }

        new Chart(canvas, configuracao);
    };

    criar('fsReceitaMensal', {
        type: 'bar',
        data: {
            labels: dados.receitaMensal.labels,
            datasets: [{
                label: 'Receita recebida',
                data: dados.receitaMensal.valores,
                backgroundColor: paleta[0],
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { tooltip: tooltipMoeda },
            scales: { y: eixoMoeda }
        }
    });

    criar('fsMrrArr', {
        type: 'line',
        data: {
            labels: dados.mrrArr.labels,
            datasets: [
                {
                    label: 'MRR',
                    data: dados.mrrArr.mrr,
                    borderColor: paleta[0],
                    backgroundColor: paleta[0],
                    tension: .25
                },
                {
                    label: 'ARR',
                    data: dados.mrrArr.arr,
                    borderColor: paleta[1],
                    backgroundColor: paleta[1],
                    tension: .25
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { tooltip: tooltipMoeda },
            scales: { y: eixoMoeda }
        }
    });

    criar('fsFluxoCaixa', {
        type: 'line',
        data: {
            labels: dados.fluxo.labels,
            datasets: [
                { label: 'Entradas previstas', data: dados.fluxo.entradasPrevistas, borderColor: paleta[4], backgroundColor: paleta[4], tension: .25 },
                { label: 'Saídas previstas', data: dados.fluxo.saidasPrevistas, borderColor: paleta[3], backgroundColor: paleta[3], tension: .25 },
                { label: 'Entradas realizadas', data: dados.fluxo.entradasRealizadas, borderColor: paleta[1], backgroundColor: paleta[1], tension: .25 },
                { label: 'Saídas realizadas', data: dados.fluxo.saidasRealizadas, borderColor: paleta[2], backgroundColor: paleta[2], tension: .25 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { tooltip: tooltipMoeda },
            scales: { y: eixoMoeda }
        }
    });

    criar('fsReceitaPlano', {
        type: 'bar',
        data: {
            labels: dados.planos.labels,
            datasets: [{
                label: 'Valor contratado',
                data: dados.planos.valores,
                backgroundColor: paleta[0],
                borderRadius: 6
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { tooltip: tooltipMoeda, legend: { display: false } },
            scales: { x: eixoMoeda }
        }
    });

    criar('fsPeriodicidade', {
        type: 'doughnut',
        data: {
            labels: dados.periodicidades.labels,
            datasets: [{
                label: 'MRR equivalente',
                data: dados.periodicidades.valores,
                backgroundColor: paleta
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { tooltip: tooltipMoeda }
        }
    });

    criar('fsStatusAssinaturas', {
        type: 'pie',
        data: {
            labels: dados.statusAssinaturas.labels,
            datasets: [{
                label: 'Assinaturas',
                data: dados.statusAssinaturas.valores,
                backgroundColor: paleta
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });

    criar('fsInadimplencia', {
        type: 'bar',
        data: {
            labels: dados.inadimplencia.labels,
            datasets: [
                {
                    label: 'Valor vencido',
                    data: dados.inadimplencia.valores,
                    backgroundColor: paleta[2],
                    borderRadius: 6,
                    yAxisID: 'y'
                },
                {
                    label: 'Quantidade',
                    data: dados.inadimplencia.quantidades,
                    type: 'line',
                    borderColor: paleta[3],
                    backgroundColor: paleta[3],
                    tension: .25,
                    yAxisID: 'yQuantidade'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                tooltip: {
                    callbacks: {
                        label(context) {
                            const valor = Number(context.raw || 0);
                            return context.dataset.yAxisID === 'y'
                                ? context.dataset.label + ': ' + moeda.format(valor)
                                : context.dataset.label + ': ' + valor.toLocaleString('pt-BR');
                        }
                    }
                }
            },
            scales: {
                y: eixoMoeda,
                yQuantidade: {
                    beginAtZero: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { precision: 0 }
                }
            }
        }
    });
})();
