import pytest
from temporalio.testing import WorkflowEnvironment


@pytest.fixture(scope="session")
async def env():
    async with await WorkflowEnvironment.start_time_skipping() as environment:
        yield environment
