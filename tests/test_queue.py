from app.queue import get_redis


def test_redis_client_has_timeouts():
    kwargs = get_redis().connection_pool.connection_kwargs
    assert kwargs["socket_connect_timeout"] == 2
    assert kwargs["socket_timeout"] == 5
