"""
Microservicio ms-cotizador (puerto 5002)
Calcula la proyección del costo mensual de estancia.
total = (plan + habitación + recargo por cuidados + extras) - descuento
"""
import os
from flask import Flask, request, jsonify

app = Flask(__name__)

RECARGOS = {'bajo': 0.00, 'medio': 100.00, 'alto': 250.00}


def _monto(valor, nombre, maximo=100000):
    if isinstance(valor, bool):
        raise ValueError(f"'{nombre}' debe ser numérico")
    try:
        valor = float(valor)
    except (TypeError, ValueError):
        raise ValueError(f"'{nombre}' debe ser numérico")
    if not (0 <= valor <= maximo):
        raise ValueError(f"'{nombre}' debe estar entre 0 y {maximo}")
    return valor


@app.get('/health')
def health():
    return jsonify({'status': 'ok', 'servicio': 'ms-cotizador'}), 200


@app.post('/api/v1/cotizar')
@app.post('/cotizar')  # ruta indicada en el documento de diseño
def cotizar_estancia():
    data = request.get_json(silent=True)
    if not isinstance(data, dict):
        return jsonify({'error': 'Se esperaba un cuerpo JSON'}), 400
    try:
        costo_base = _monto(data.get('costo_base', 0), 'costo_base')
        costo_habitacion = _monto(data.get('costo_habitacion', 0), 'costo_habitacion')
        descuento_pct = _monto(data.get('descuento_pct', 0), 'descuento_pct', 50)
        nivel = str(data.get('nivel_cuidados', 'medio')).lower()
        if nivel not in RECARGOS:
            raise ValueError("'nivel_cuidados' debe ser bajo, medio o alto")
        extras = data.get('servicios_extra', [])
        if not isinstance(extras, list):
            raise ValueError("'servicios_extra' debe ser una lista")
        total_extras = sum(_monto(s.get('precio', 0), 'precio') for s in extras if isinstance(s, dict))
    except ValueError as e:
        return jsonify({'error': str(e)}), 400

    recargo = RECARGOS[nivel]
    subtotal = round(costo_base + costo_habitacion + recargo + total_extras, 2)
    monto_descuento = round(subtotal * descuento_pct / 100, 2)

    return jsonify({
        'costo_base': costo_base,
        'costo_habitacion': costo_habitacion,
        'recargo_cuidado': recargo,
        'total_extras': round(total_extras, 2),
        'subtotal': subtotal,
        'descuento_pct': descuento_pct,
        'monto_descuento': monto_descuento,
        'monto_total': round(subtotal - monto_descuento, 2),
        'status': 'success',
    }), 200


if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5002, debug=os.getenv('FLASK_DEBUG') == '1')
