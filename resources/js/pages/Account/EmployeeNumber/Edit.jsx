import ActionButton from '@/components/ActionButton';
import useForm from '@/hooks/useForm';
import ContainerBox from '@/layouts/ContainerBox';
import GuestLayout from '@/layouts/GuestLayout';
import { router, usePage } from '@inertiajs/react';
import { Anchor, Group, Text, TextInput, Title } from '@mantine/core';

const EmployeeNumberEdit = () => {
  const { auth } = usePage().props;

  const [form, submit, updateValue] = useForm('post', route('account.employee-number.update'), {
    _method: 'put',
    employee_number: '',
  });

  return (
    <>
      <Title ta='center'>One more thing</Title>
      <Text
        c='dimmed'
        size='sm'
        ta='center'
        mt={5}
      >
        Hi {auth.user.name}, please provide your employee number to continue.
      </Text>

      <form onSubmit={submit}>
        <ContainerBox
          shadow='md'
          p={30}
          mt={30}
          radius='md'
        >
          <TextInput
            label='Employee number'
            placeholder='e.g. 2024-00123'
            required
            autoFocus
            value={form.data.employee_number}
            onChange={e => updateValue('employee_number', e.target.value)}
            error={form.errors.employee_number}
          />

          <Group
            justify='space-between'
            mt='xl'
          >
            <Anchor
              size='sm'
              onClick={() => router.delete(route('logout'))}
            >
              Log out
            </Anchor>
            <ActionButton loading={form.processing}>Save and continue</ActionButton>
          </Group>
        </ContainerBox>
      </form>
    </>
  );
};

EmployeeNumberEdit.layout = page => <GuestLayout title='Employee number'>{page}</GuestLayout>;

export default EmployeeNumberEdit;
