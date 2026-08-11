import ActionButton from '@/components/ActionButton';
import useForm from '@/hooks/useForm';
import ContainerBox from '@/layouts/ContainerBox';
import Layout from '@/layouts/MainLayout';
import { getInitials } from '@/utils/user';
import { usePage } from '@inertiajs/react';
import {
  Anchor,
  Avatar,
  Divider,
  FileInput,
  Grid,
  Group,
  MultiSelect,
  PasswordInput,
  Text,
  TextInput,
  Title,
} from '@mantine/core';

const ProfileIndex = () => {
  const { auth, user, projectTags } = usePage().props;
  const isClient = auth.user.roles.includes('client');

  const [form, submit, updateValue] = useForm('post', route('account.profile.update', user.id), {
    _method: 'put',
    avatar: null,
    job_title: user.job_title,
    employee_number: user.employee_number || '',
    name: user.name,
    phone: user.phone || '',
    email: user.email,
    password: '',
    password_confirmation: '',
    default_project_tag_ids: (user.default_project_tag_ids || []).map(String),
  });

  return (
    <>
      <Grid
        justify='space-between'
        align='flex-end'
        gutter='xl'
        mb='lg'
      >
        <Grid.Col span='auto'>
          <Title order={1}>My Profile</Title>
        </Grid.Col>
        <Grid.Col span='content'></Grid.Col>
      </Grid>

      <ContainerBox maw={600}>
        <form onSubmit={e => submit(e, { forceFormData: true })}>
          <Grid
            justify='flex-start'
            align='flex-start'
            gutter='lg'
          >
            <Grid.Col span='content'>
              <Avatar
                src={
                  form.data.avatar === null ? user.avatar : URL.createObjectURL(form.data.avatar)
                }
                size={120}
                color='blue'
              >
                {getInitials(form.data.name)}
              </Avatar>
            </Grid.Col>
            <Grid.Col span='auto'>
              <FileInput
                label='Profile image'
                placeholder='Choose image'
                accept='image/png,image/jpeg'
                onChange={image => updateValue('avatar', image)}
                clearable
                error={form.errors.avatar}
              />
              <Text
                size='xs'
                c='dimmed'
                mt='sm'
              >
                If no image is uploaded we will try to fetch it via{' '}
                <Anchor
                  href='https://unavatar.io'
                  target='_blank'
                  opacity={0.6}
                >
                  unavatar.io
                </Anchor>{' '}
                service.
              </Text>
            </Grid.Col>
          </Grid>

          <TextInput
            label='Name'
            placeholder='User full name'
            required
            mt='md'
            value={form.data.name}
            onChange={e => updateValue('name', e.target.value)}
            error={form.errors.name}
          />

          <TextInput
            label='Job title'
            placeholder='e.g. Frontend Developer'
            required
            mt='md'
            value={form.data.job_title}
            onChange={e => updateValue('job_title', e.target.value)}
            error={form.errors.job_title}
          />

          <TextInput
            label='Employee number'
            placeholder='e.g. 2024-00123'
            required={!isClient}
            mt='md'
            value={form.data.employee_number}
            onChange={e => updateValue('employee_number', e.target.value)}
            error={form.errors.employee_number}
          />

          <TextInput
            label='Phone'
            placeholder='Users phone number'
            mt='md'
            value={form.data.phone}
            onChange={e => updateValue('phone', e.target.value)}
            error={form.errors.phone}
          />

          <Divider
            mt='xl'
            mb='md'
            label='Preferences'
            labelPosition='center'
          />

          <MultiSelect
            label='Default project tag filter'
            description='These tags are pre-selected in the tag filter on the Dashboard and Projects pages.'
            placeholder={form.data.default_project_tag_ids.length ? undefined : 'Select tags'}
            mt='md'
            searchable
            clearable
            value={form.data.default_project_tag_ids}
            onChange={values => updateValue('default_project_tag_ids', values)}
            data={projectTags.map(tag => ({ value: tag.id.toString(), label: tag.name }))}
            error={form.errors.default_project_tag_ids}
          />

          <Divider
            mt='xl'
            mb='md'
            label='Login credentials'
            labelPosition='center'
          />

          <TextInput
            label='Email'
            placeholder='User email'
            required
            value={form.data.email}
            onChange={e => updateValue('email', e.target.value)}
            onBlur={() => form.validate('email')}
            error={form.errors.email}
          />

          <PasswordInput
            label='Password'
            placeholder='User password'
            mt='md'
            value={form.data.password}
            onChange={e => updateValue('password', e.target.value)}
            error={form.errors.password}
          />

          <PasswordInput
            label='Confirm password'
            placeholder='Confirm password'
            mt='md'
            value={form.data.password_confirmation}
            onChange={e => updateValue('password_confirmation', e.target.value)}
            error={form.errors.password_confirmation}
          />

          <Group
            justify='flex-end'
            mt='xl'
          >
            <ActionButton loading={form.processing}>Update</ActionButton>
          </Group>
        </form>
      </ContainerBox>
    </>
  );
};

ProfileIndex.layout = page => <Layout title='Profile'>{page}</Layout>;

export default ProfileIndex;
